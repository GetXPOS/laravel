<?php

namespace GetXPOS\Laravel;

use GetXPOS\Laravel\Support\HostKeys;
use Symfony\Component\Process\Process;

class XposTunnel
{
    /**
     * The parsed public URL (HTTPS URL or ip:port for TCP).
     */
    public ?string $url = null;

    /**
     * The parsed expiry timestamp (RFC3339).
     */
    public ?string $expiresAt = null;

    /**
     * Whether the tunnel is connected.
     */
    public bool $connected = false;

    /**
     * LV1: once the connect has resolved, the output callback stops
     * retaining/parsing so the buffer can't grow for the tunnel's lifetime.
     */
    private bool $settled = false;

    /**
     * The SSH process.
     */
    private ?Process $process = null;

    /**
     * Tunnel options.
     */
    private int $port;
    private string $host;
    private ?string $token;
    private ?string $subdomain;
    private ?string $domain;
    private string $mode;
    private string $server;
    private int $sshPort;

    /**
     * Callbacks.
     */
    private $onConnectCallback = null;
    private $onCloseCallback = null;
    private $onOutputCallback = null;

    /**
     * Timeouts.
     */
    private const CONNECT_TIMEOUT = 15;
    private const KILL_TIMEOUT = 3;

    /** LV1: cap the parse buffer so a post-URL output burst can't grow it unbounded. */
    private const MAX_PARSE_BUFFER = 65536;

    /**
     * LV1: bounded wait (ms), after the URL banner is seen, for a later-chunk
     * "Expires:" line before resolving — resolves SUCCESS on the cap when none
     * arrives (paid / no-expiry tiers never send it).
     */
    private const EXPIRY_WINDOW_MS = 500;

    /** Default SSH server (must match the well-known fleet domain). */
    private const DEFAULT_SERVER = 'go.xpos.dev';

    /**
     * Path to the per-process known_hosts file pinning the xpos.dev
     * fleet's host key. Null when the caller is using a custom server
     * (pinning doesn't apply) or before start() runs.
     */
    private ?string $knownHostsPath = null;

    /**
     * Per-process ssh_config dir (mode 0700). The auth token (User
     * directive) lives in `<dir>/config` (mode 0600) so `ps`/`/proc/<pid>/
     * cmdline` cannot harvest it. Cleared on every exit path.
     */
    private ?string $sshConfigDir = null;

    /** Closure that removes the known_hosts temp dir; null when none. */
    private $knownHostsCleanup = null;

    /**
     * Create a new XposTunnel instance.
     *
     * @param array $options Tunnel options
     * @throws \InvalidArgumentException If port is missing
     */
    public function __construct(array $options = [])
    {
        $port = filter_var($options['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \InvalidArgumentException('port is required (1-65535)');
        }

        $this->port = $port;
        // Default to 127.0.0.1 (not localhost) to avoid IPv6 ::1 resolution
        // mismatches when the dev server only binds IPv4. Matches the CLI
        // default in XposCommand.php and the other XPOS SDKs.
        $this->host = $options['host'] ?? '127.0.0.1';
        $this->token = static::resolveToken($options['token'] ?? null);
        $this->subdomain = $options['subdomain'] ?? null;
        $this->domain = $options['domain'] ?? null;
        $this->mode = $options['mode'] ?? 'http';
        $this->server = $options['server'] ?? self::configGet('xpos.server', 'go.xpos.dev');
        $this->sshPort = (int) ($options['sshPort'] ?? self::configGet('xpos.ssh_port', 443));

        if (!in_array($this->mode, ['http', 'tcp'], true)) {
            throw new \InvalidArgumentException('Mode must be "http" or "tcp"');
        }
        if ($this->mode === 'tcp' && !$this->token) {
            throw new \InvalidArgumentException('tcp mode requires a token');
        }

        if ($this->subdomain && $this->domain) {
            throw new \InvalidArgumentException('Cannot use both "subdomain" and "domain"');
        }
        if (($this->subdomain || $this->domain) && !$this->token) {
            throw new \InvalidArgumentException(
                ($this->domain ? '"domain"' : '"subdomain"') . ' requires a token'
            );
        }

        // Reject control / whitespace in any value rendered into ssh_config.
        // ssh_config is line-based — a stray newline (e.g. injected via
        // config('xpos.server') from .env) would smuggle a new directive
        // into the file.
        self::validateSshConfigValue('server', $this->server);
        self::validateSshConfigValue('host', $this->host);
        if ($this->subdomain !== null) {
            self::validateSshConfigValue('subdomain', $this->subdomain);
            self::validateDnsName('subdomain', $this->subdomain);
        }
        if ($this->domain !== null) {
            self::validateSshConfigValue('domain', $this->domain);
            self::validateDnsName('domain', $this->domain);
        }
        if ($this->token !== null) {
            self::validateSshConfigValue('token', $this->token);
        }
    }

    /**
     * Set the onConnect callback (fluent).
     */
    public function onConnect(callable $callback): static
    {
        $this->onConnectCallback = $callback;
        return $this;
    }

    /**
     * Set the onClose callback (fluent).
     */
    public function onClose(callable $callback): static
    {
        $this->onCloseCallback = $callback;
        return $this;
    }

    /**
     * Set the onOutput callback (fluent).
     */
    public function onOutput(callable $callback): static
    {
        $this->onOutputCallback = $callback;
        return $this;
    }

    /**
     * Start the SSH tunnel. Blocks until URL is found or timeout.
     *
     * @return string The tunnel URL
     * @throws \RuntimeException If connection fails
     */
    public function start(): string
    {
        if ($this->connected) {
            throw new \RuntimeException('Tunnel is already connected');
        }

        $this->url = null;
        $this->expiresAt = null;
        $this->settled = false;

        // Resolve the SSH host key before spawning ssh. Fail closed: if we
        // cannot produce a pinned known_hosts file (network down AND no
        // fresh disk cache), refuse to connect rather than silently fall
        // back to TOFU. Custom servers skip pinning (returns null path).
        try {
            $resolved = HostKeys::resolve($this->server, $this->sshPort, self::DEFAULT_SERVER);
        } catch (\Throwable $err) {
            throw new \RuntimeException('ssh host key pinning: ' . $err->getMessage(), 0, $err);
        }
        $this->knownHostsPath = $resolved['path'];
        $this->knownHostsCleanup = $resolved['cleanup'];

        try {
            $cfgPath = $this->writeSshConfig();
        } catch (\Throwable $err) {
            $this->cleanupHostKeys();
            $this->cleanupSshConfig();
            throw new \RuntimeException('write ssh_config: ' . $err->getMessage(), 0, $err);
        }

        $args = ['ssh', '-F', $cfgPath, 'xpos'];
        $this->process = new Process($args);
        $this->process->setTimeout(null);

        $outputBuffer = '';
        $error = null;

        $this->process->start(function ($type, $buffer) use (&$outputBuffer, &$error) {
            // Always forward raw output to the per-chunk callback.
            if ($this->onOutputCallback) {
                ($this->onOutputCallback)($buffer);
            }

            // LV1: once resolved, stop retaining/parsing so the buffer can't grow
            // for the tunnel's lifetime — output was already forwarded above.
            if ($this->settled) {
                return;
            }

            // Accumulate, bounded.
            $outputBuffer .= $buffer;
            if (strlen($outputBuffer) > self::MAX_PARSE_BUFFER) {
                $outputBuffer = substr($outputBuffer, -self::MAX_PARSE_BUFFER);
            }

            // Parse URL
            if (!$this->url) {
                $this->url = $this->parseUrl($outputBuffer);
            }

            // Parse expiry
            if (!$this->expiresAt) {
                $this->expiresAt = $this->parseExpiry($outputBuffer);
            }

            // C14: scan the ACCUMULATED buffer (not just this chunk) so an
            // "Error:" split across a read boundary is still detected.
            if (!$error) {
                $lines = explode("\n", $outputBuffer);
                foreach ($lines as $line) {
                    $line = trim($line, "\r\n ");
                    $parsed = $this->parseError($line);
                    if ($parsed) {
                        $error = $parsed;
                        break;
                    }
                }
            }
        });

        // Poll for URL or failure
        $waitedMs = 0;
        $maxWaitMs = self::CONNECT_TIMEOUT * 1000;

        while (!$this->url && !$error && $waitedMs < $maxWaitMs && $this->process->isRunning()) {
            usleep(100000); // 100ms
            $waitedMs += 100;
        }

        // LV1: the URL arrived but the server emits "Expires:" in a SEPARATE
        // write that may land in a later chunk. Wait a short bounded window so
        // $this->expiresAt is populated before start() returns (the synchronous
        // CLI reads it immediately); resolve regardless when the cap elapses.
        if ($this->url && !$this->expiresAt && !$error) {
            $expiryWaitedMs = 0;
            while (!$this->expiresAt && $expiryWaitedMs < self::EXPIRY_WINDOW_MS && $this->process->isRunning()) {
                usleep(50000); // 50ms
                $expiryWaitedMs += 50;
            }
        }

        // Check for error from SSH output
        if ($error) {
            $this->killProcess();
            throw new \RuntimeException($error);
        }

        // Check if process died
        if (!$this->process->isRunning() && !$this->url) {
            $errorOutput = trim($this->process->getErrorOutput());
            $this->cleanupHostKeys();
            $this->cleanupSshConfig();
            throw new \RuntimeException(
                'Tunnel connection failed' . ($errorOutput ? ": {$errorOutput}" : '')
            );
        }

        // Timeout
        if (!$this->url) {
            $this->killProcess();
            throw new \RuntimeException('Tunnel connection timed out');
        }

        // LV1: connect resolved — the callback now only forwards output (no more
        // appending/parsing), so the buffer stops growing during wait().
        $this->settled = true;
        $this->connected = true;

        // Fire onConnect callback
        if ($this->onConnectCallback) {
            ($this->onConnectCallback)([
                'url' => $this->url,
                'expiresAt' => $this->expiresAt,
            ]);
        }

        return $this->url;
    }

    /**
     * Gracefully close the tunnel.
     */
    public function close(): void
    {
        if (!$this->process) {
            return;
        }

        // Only emit onClose for a tunnel that actually connected — a failed
        // start() (process spawned but never connected) shouldn't fire a close
        // event without a matching connect.
        $wasConnected = $this->connected;
        $this->connected = false;

        if ($this->process->isRunning()) {
            $this->process->stop(self::KILL_TIMEOUT);
        }

        $this->process = null;
        $this->cleanupHostKeys();
        $this->cleanupSshConfig();

        if ($wasConnected && $this->onCloseCallback) {
            ($this->onCloseCallback)(null);
        }
    }

    /**
     * Block until the SSH process exits. Returns exit code.
     */
    public function wait(): int
    {
        while ($this->process && $this->process->isRunning()) {
            usleep(100000); // 100ms
        }

        $this->connected = false;
        $exitCode = $this->process?->getExitCode() ?? 1;
        $this->process = null;
        $this->cleanupHostKeys();
        $this->cleanupSshConfig();

        if ($this->onCloseCallback) {
            ($this->onCloseCallback)($exitCode);
        }

        return $exitCode;
    }

    /**
     * F29: reclaim the ssh_config temp dir (a 0600 file holding the tk_ token)
     * and the known_hosts dir if the tunnel is garbage-collected without an
     * explicit close()/wait(). Uses the cleanup-only killProcess() path, NOT
     * close() — firing the user's onClose callback during GC would be
     * surprising. cleanupHostKeys()/cleanupSshConfig() are idempotent, so a
     * later explicit close() remains safe.
     */
    public function __destruct()
    {
        $this->killProcess();
    }

    /**
     * Check if the tunnel is currently connected.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->process?->isRunning();
    }

    /**
     * Convenience factory: create and start a tunnel.
     *
     * @param array $options Tunnel options
     * @return static The connected tunnel
     */
    public static function connect(array $options = []): static
    {
        $tunnel = new static($options);
        $tunnel->start();
        return $tunnel;
    }

    // -------------------------------------------------------------------------
    // Public Static Utilities
    // -------------------------------------------------------------------------

    /**
     * Resolve the auth token from argument, config, or env.
     */
    public static function resolveToken(?string $token = null): ?string
    {
        $token = $token ?? self::configGet('xpos.token') ?? env('XPOS_TOKEN');

        if (!$token) {
            return null;
        }

        // Normalize: prepend tk_ if missing
        if (!str_starts_with($token, 'tk_')) {
            $token = 'tk_' . $token;
        }

        return $token;
    }

    /**
     * Check if a line should be filtered from output display.
     * Matches Node.js and Python SDK shouldFilterLine() exactly.
     */
    public static function shouldFilterLine(string $line): bool
    {
        $line = trim($line);

        if ($line === '') {
            return true;
        }

        // Patterns to suppress (displayed by the command itself)
        $patterns = [
            '/^Tunnel created!/i',
            '/^TCP tunnel created!/i',
            '/^HTTP:/i',
            '/^HTTPS:/i',
            '/^Expires:/i',
            '/^Press Ctrl\+C/i',
            '/^Tunnel closed/i',
            '/^\S+:\d+$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format an RFC3339 expiry timestamp into a human-readable countdown.
     * Returns plain text (no ANSI colors).
     */
    public static function formatExpiry(string $rfc3339): string
    {
        try {
            $expires = new \DateTimeImmutable($rfc3339);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($expires <= $now) {
                return 'Expired';
            }

            $diff = $now->diff($expires);
            $totalHours = ($diff->days * 24) + $diff->h;
            $minutes = $diff->i;
            $utcTime = $expires->setTimezone(new \DateTimeZone('UTC'))->format('H:i');

            if ($totalHours > 0) {
                return "Expires in {$totalHours}h {$minutes}m ({$utcTime} UTC)";
            }

            return "Expires in {$minutes}m ({$utcTime} UTC)";
        } catch (\Throwable) {
            return "Expires: {$rfc3339}";
        }
    }

    // -------------------------------------------------------------------------
    // Private Methods
    // -------------------------------------------------------------------------

    /**
     * Render the per-process ssh_config body. The auth token lives in the
     * `User` directive inside the file rather than on argv so
     * `ps`/`/proc/<pid>/cmdline` don't surface it. HostKeyAlias MUST exactly
     * match the marker the host-keys writer uses (`[host]:port`).
     *
     * For the xpos.dev fleet the SDK pins the host key fetched from
     * https://xpos.dev/.well-known/ssh-host-keys via a per-process
     * known_hosts file referenced by $this->knownHostsPath. For custom
     * servers $this->knownHostsPath stays null and the legacy accept-new
     * behaviour is used. The well-known URL is hard-coded to xpos.dev.
     */
    private function buildSshConfig(): string
    {
        [$bind, $target] = $this->buildRemoteForwardConfig();
        $user = $this->buildSshUser();

        if ($this->knownHostsPath) {
            $knownHostsBlock = "    StrictHostKeyChecking yes\n"
                . '    UserKnownHostsFile ' . self::quoteSshConfigPath($this->knownHostsPath) . "\n";
        } else {
            $knownHostsBlock = "    StrictHostKeyChecking accept-new\n"
                . '    UserKnownHostsFile ' . self::quoteSshConfigPath('~/.ssh/xpos_known_hosts') . "\n";
        }

        return "Host xpos\n"
            . "    HostName {$this->server}\n"
            . "    HostKeyAlias [{$this->server}]:{$this->sshPort}\n"
            . "    User {$user}\n"
            . "    Port {$this->sshPort}\n"
            . $knownHostsBlock
            . "    LogLevel ERROR\n"
            . "    ConnectTimeout 10\n"
            . "    RemoteForward {$bind} {$target}\n";
    }

    /**
     * Materialize the ssh_config in a private 0700 dir with file mode 0600.
     * Returns the file path. Caller MUST invoke cleanupSshConfig() on every
     * exit path. Don't use tempnam() alone — its parent (/tmp) is sticky-bit
     * world-writable, so we wrap the file in our own private dir.
     */
    private function writeSshConfig(): string
    {
        $base = sys_get_temp_dir();
        // Mimic mkdtemp: retry until exclusive creation succeeds.
        for ($i = 0; $i < 8; $i++) {
            $dir = $base . DIRECTORY_SEPARATOR . 'xpos-ssh-' . bin2hex(random_bytes(6));
            if (@mkdir($dir, 0700, false)) {
                @chmod($dir, 0700);
                $cfg = $dir . DIRECTORY_SEPARATOR . 'config';
                $bytes = file_put_contents($cfg, $this->buildSshConfig(), LOCK_EX);
                if ($bytes === false) {
                    @rmdir($dir);
                    throw new \RuntimeException('failed to write ssh_config');
                }
                @chmod($cfg, 0600);
                $this->sshConfigDir = $dir;
                return $cfg;
            }
        }
        throw new \RuntimeException('failed to create ssh_config dir');
    }

    /**
     * Recursively remove the per-process ssh_config dir if any. Idempotent.
     */
    private function cleanupSshConfig(): void
    {
        $dir = $this->sshConfigDir;
        $this->sshConfigDir = null;
        if (!$dir || !is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build the bind/target pair for an ssh_config RemoteForward directive.
     * @return array{0:string,1:string} [bind, target]
     */
    private function buildRemoteForwardConfig(): array
    {
        $target = "{$this->host}:{$this->port}";
        if ($this->domain) {
            return ["{$this->domain}:80", $target];
        }
        if ($this->subdomain) {
            return ["{$this->subdomain}:80", $target];
        }
        return ['0', $target];
    }

    /**
     * Reject characters that would either smuggle a new directive into
     * ssh_config (CR/LF/NUL) or produce an ambiguously-tokenised directive
     * (whitespace inside identifier-shaped fields like User/HostName/
     * RemoteForward bind). Reject-set is [\x00-\x20\x7F] — whitespace +
     * control + DEL. Applied to caller-supplied identifiers (server, host,
     * subdomain, domain, token) at the constructor boundary.
     *
     * @throws \InvalidArgumentException
     */
    private static function validateSshConfigValue(string $name, string $value): void
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($value[$i]);
            if ($code <= 0x20 || $code === 0x7F) {
                throw new \InvalidArgumentException(
                    "{$name} contains disallowed control or whitespace character at byte {$i}"
                );
            }
        }
    }

    /**
     * Validate a DNS-shaped identifier (subdomain or custom domain). Each
     * dot-separated label must match
     * ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ — the same per-label rule the
     * server enforces. Catches obvious typos (foo..bar, leading/trailing
     * hyphen, underscore, labels over 63 chars) on the client so the SSH
     * session fails fast with a clear error rather than after a handshake
     * with a cryptic banner.
     *
     * @throws \InvalidArgumentException
     */
    private static function validateDnsName(string $name, string $value): void
    {
        $labels = explode('.', strtolower($value));
        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                throw new \InvalidArgumentException(
                    "{$name} is not a valid DNS name (lowercase a-z, 0-9, hyphens; no leading/trailing hyphen; max 63 chars per label)"
                );
            }
        }
    }

    /**
     * Quote a value for ssh_config. OpenSSH on Windows accepts forward
     * slashes, so we normalize backslashes before quoting to sidestep
     * escaping. Only wraps in double quotes when needed.
     */
    private static function quoteSshConfigPath(string $value): string
    {
        $normalized = str_replace('\\', '/', $value);
        if (!preg_match('/[\s"\\\\]/', $normalized)) {
            return $normalized;
        }
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $normalized);
        return '"' . $escaped . '"';
    }

    /**
     * Build the SSH username.
     */
    private function buildSshUser(): string
    {
        if (!$this->token) {
            return 'x';
        }

        if ($this->mode === 'tcp') {
            return $this->token . '+tcp';
        }

        return $this->token;
    }

    /**
     * Parse URL from output buffer. Mode-aware.
     */
    private function parseUrl(string $buffer): ?string
    {
        if ($this->mode === 'tcp') {
            // TCP mode: match "tunnel created!\n  ip:port"
            if (preg_match('/tunnel created!\r?\n\s+(\S+:\d+)/i', $buffer, $matches)) {
                return rtrim($matches[1], "\r\n");
            }
            return null;
        }

        // HTTP mode: prefer HTTPS, fallback to HTTP
        if (preg_match('/HTTPS:\s+(https:\/\/\S+)/i', $buffer, $matches)) {
            return rtrim($matches[1], "\r\n");
        }

        if (preg_match('/HTTP:\s+(https?:\/\/\S+)/i', $buffer, $matches)) {
            return rtrim($matches[1], "\r\n");
        }

        return null;
    }

    /**
     * Parse expiry from output buffer.
     */
    private function parseExpiry(string $buffer): ?string
    {
        if (preg_match('/Expires:\s+(\S+)/', $buffer, $matches)) {
            return rtrim($matches[1], "\r\n");
        }

        return null;
    }

    /**
     * Parse error from a single line.
     */
    private function parseError(string $line): ?string
    {
        if (preg_match('/^Error:\s*(.+)/', $line, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Kill the SSH process.
     */
    private function killProcess(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop(self::KILL_TIMEOUT);
        }
        // Reclaim the per-process known_hosts temp dir even when the
        // connection failed before reaching close() — start() throws on
        // timeout/error after spawn but the cleanup must still run.
        $this->cleanupHostKeys();
        $this->cleanupSshConfig();
    }

    /**
     * Run the pinning cleanup closure exactly once. Safe to call from
     * multiple paths; idempotent.
     */
    private function cleanupHostKeys(): void
    {
        if ($this->knownHostsCleanup) {
            $cleanup = $this->knownHostsCleanup;
            $this->knownHostsCleanup = null;
            $this->knownHostsPath = null;
            try {
                $cleanup();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /**
     * Safe config helper — works outside full Laravel bootstrap.
     */
    private static function configGet(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
