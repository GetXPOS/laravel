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

        $body = @file_get_contents(self::URL, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('ssh-host-keys: HTTP request failed');
        }

        // Parse status from $http_response_header (set by file_get_contents).
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status > 0 && (int) ($status / 100) !== 2) {
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
            if (!isset($k['type'], $k['public_key'])) {
                continue;
            }
            $lines .= sprintf("[%s]:%d %s %s\n", $host, $port, $k['type'], $k['public_key']);
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
