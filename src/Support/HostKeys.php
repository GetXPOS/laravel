<?php

namespace GetXPOS\Laravel\Support;

/**
 * SSH host key pinning for the xpos.dev fleet.
 *
 * Fetches the official SSH host key from
 * https://xpos.dev/.well-known/ssh-host-keys before opening the tunnel,
 * writes a per-process known_hosts file, and points OpenSSH at it via
 * StrictHostKeyChecking=yes. This closes the trust-on-first-use window
 * where a wire-MITM could substitute their own host key on first connect
 * and harvest the auth token visible in argv.
 *
 * Custom servers (when `server` is overridden) skip pinning and fall
 * back to the legacy `accept-new` behaviour — the well-known URL is
 * hard-coded to xpos.dev and only valid for that fleet.
 */
class HostKeys
{
    /** Public well-known URL. Hard-coded — only valid for the official fleet. */
    public const URL = 'https://xpos.dev/.well-known/ssh-host-keys';

    /** Track whether the custom-server warning has fired this process. */
    private static bool $warnedCustomServer = false;

    /**
     * Bounds how long an offline SDK can rely on a stale cache. Older
     * caches are treated as missing so a rotation eventually invalidates
     * local copies even on machines that go offline.
     */
    private const CACHE_MAX_AGE_S = 30 * 24 * 60 * 60;

    /** Tight inner timeout on the well-known fetch (seconds). */
    private const FETCH_TIMEOUT_S = 5;

    /**
     * Cap on the well-known response body (64 KiB — parity with the Go /
     * Node / Python SDKs' N4 hardening). The stream timeout is a per-read
     * stall timeout, not a total wall-clock or byte bound, so without a
     * maxlen a slowly-dripping endpoint could buffer an unbounded body
     * into memory before json_decode.
     */
    private const MAX_FETCH_BYTES = 65536;

    /**
     * Resolve a per-process known_hosts file path for the given server.
     *
     * Returns ['path' => null, 'cleanup' => fn] for custom servers so the
     * SDK falls back to the legacy accept-new behaviour. For the default
     * fleet, returns the path to a freshly written known_hosts file plus
     * a cleanup closure that removes its temp directory.
     *
     * Throws \RuntimeException on pinning failure so the SDK can fail
     * closed rather than silently downgrading to TOFU.
     *
     * @return array{path: ?string, cleanup: callable}
     */
    public static function resolve(string $server, int $port, string $defaultServer): array
    {
        $noop = static function (): void {};

        if ($server !== $defaultServer) {
            if (!self::$warnedCustomServer) {
                fwrite(
                    STDERR,
                    "warning: xpos host-key pinning disabled for custom server '{$server}'. "
                    . "Verify the fingerprint manually on first connect.\n"
                );
                self::$warnedCustomServer = true;
            }
            return ['path' => null, 'cleanup' => $noop];
        }

        $doc = self::loadOrFetch();
        [$path, $dir] = self::writeKnownHosts($doc, $server, $port);

        $cleanup = static function () use ($dir): void {
            self::removeDir($dir);
        };

        return ['path' => $path, 'cleanup' => $cleanup];
    }

    /**
     * Prefer a fresh network fetch (best authority); fall back to the
     * disk cache when offline. Throws when both paths fail.
     */
    private static function loadOrFetch(): array
    {
        $fetchErr = null;
        try {
            $doc = self::fetch();
            // Best-effort cache write.
            try {
                self::saveCache($doc);
            } catch (\Throwable) {
                // ignore
            }
            return $doc;
        } catch (\Throwable $err) {
            $fetchErr = $err;
        }

        $cached = self::loadCache();
        if ($cached !== null) {
            return $cached;
        }

        throw new \RuntimeException(
            'fetch ssh host keys: ' . ($fetchErr?->getMessage() ?? 'unknown error')
        );
    }

    private static function fetch(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::FETCH_TIMEOUT_S,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => self::FETCH_TIMEOUT_S,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents(self::URL, false, $ctx, 0, self::MAX_FETCH_BYTES);
        if ($body === false) {
            throw new \RuntimeException('ssh-host-keys: HTTP request failed');
        }

        // Parse status from $http_response_header (set by file_get_contents).
        // A87: keep the LAST HTTP status line — file_get_contents follows
        // redirects, so $http_response_header accumulates each hop's status and
        // [0] could be a 3xx from a redirect chain (a latent false-reject).
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        // A87: require a successfully parsed 2xx status before trusting the body.
        // An unparseable status line ($status === 0) or any non-2xx must fail
        // closed here, not fall through to json_decode on an error body (the
        // empty-key-list check below was only an accidental backstop).
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("ssh-host-keys: HTTP {$status}");
        }

        $doc = json_decode($body, true);
        if (!is_array($doc) || empty($doc['keys']) || !is_array($doc['keys'])) {
            throw new \RuntimeException('ssh-host-keys: empty key list');
        }
        return $doc;
    }

    private static function cacheFile(): string
    {
        $home = self::homeDir();
        return $home . DIRECTORY_SEPARATOR . '.xpos' . DIRECTORY_SEPARATOR . 'host-keys.json';
    }

    private static function homeDir(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (!$home) {
            // Fall back to system temp so we still have *somewhere* to
            // cache, even on locked-down CI workers where neither var is
            // set. Not ideal but better than crashing.
            $home = sys_get_temp_dir();
        }
        return $home;
    }

    private static function loadCache(): ?array
    {
        $path = self::cacheFile();
        if (!is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::CACHE_MAX_AGE_S) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || empty($doc['keys']) || !is_array($doc['keys'])) {
            return null;
        }
        return $doc;
    }

    private static function saveCache(array $doc): void
    {
        $path = self::cacheFile();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }
        $tmp = $path . '.tmp';
        $written = @file_put_contents($tmp, json_encode($doc));
        if ($written === false) {
            return;
        }
        @chmod($tmp, 0o600);
        @rename($tmp, $path);

        // Best-effort: reclaim ownership of the cache file for the current
        // user, so a file planted in the cache dir by another local user is
        // not silently relied upon on the next load. POSIX-only — composer.json
        // does NOT require ext-posix, so calling posix_* unguarded would fatal
        // on Windows or in a minimal container. Guard on the functions existing,
        // and only chown when our effective UID already owns the cache dir (we
        // have no business chowning a dir we don't own). Skip silently
        // otherwise — the 0600 mode on the temp file already restricts access.
        if (function_exists('posix_getuid') && function_exists('posix_geteuid')) {
            $dirOwner = @fileowner($dir);
            if ($dirOwner !== false && $dirOwner === posix_geteuid()) {
                @chown($path, posix_getuid());
            }
        }
    }

    /**
     * @return array{0: string, 1: string} [path, dir]
     */
    private static function writeKnownHosts(array $doc, string $host, int $port): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xpos-' . bin2hex(random_bytes(8));
        if (!@mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new \RuntimeException('failed to create known_hosts dir');
        }
        $path = $base . DIRECTORY_SEPARATOR . 'known_hosts';
        $lines = '';
        foreach ($doc['keys'] as $k) {
            // A14/A15: fail closed before pinning, mirroring the Go SDK's
            // verifyHostKeys. Require type / public_key / fingerprint_sha256;
            // reject whitespace/control chars (known_hosts is line-oriented, so a
            // newline could smuggle an extra `* <attacker-key>` wildcard pin
            // line); and re-derive the OpenSSH SHA256 fingerprint, throwing on
            // disagreement with the advertised one. Authenticity ultimately
            // comes from TLS to the well-known host — this is a defense-in-depth
            // integrity cross-check.
            $type = $k['type'] ?? null;
            $pub = $k['public_key'] ?? null;
            $advertised = $k['fingerprint_sha256'] ?? null;
            if (!is_string($type) || !is_string($pub) || !is_string($advertised)
                || $type === '' || $pub === '' || $advertised === '') {
                self::removeDir($base);
                throw new \RuntimeException('ssh-host-keys: missing type/public_key/fingerprint_sha256 from server');
            }
            if (preg_match('/\s/', $type) || preg_match('/\s/', $pub)) {
                self::removeDir($base);
                throw new \RuntimeException('ssh-host-keys: malformed type or public_key shape from server');
            }
            $raw = base64_decode($pub, true);
            if ($raw === false) {
                self::removeDir($base);
                throw new \RuntimeException('ssh-host-keys: public_key is not valid base64');
            }
            $derived = 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
            if ($derived !== $advertised) {
                self::removeDir($base);
                throw new \RuntimeException(sprintf(
                    'ssh host key fingerprint mismatch: server-advertised %s != re-derived %s',
                    $advertised,
                    $derived
                ));
            }
            $lines .= sprintf("[%s]:%d %s %s\n", $host, $port, $type, $pub);
        }
        if ($lines === '') {
            self::removeDir($base);
            throw new \RuntimeException('no host keys to write');
        }
        if (@file_put_contents($path, $lines) === false) {
            self::removeDir($base);
            throw new \RuntimeException('failed to write known_hosts');
        }
        @chmod($path, 0o600);
        return [$path, $base];
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($full)) {
                    self::removeDir($full);
                } else {
                    @unlink($full);
                }
            }
        }
        @rmdir($dir);
    }
}
