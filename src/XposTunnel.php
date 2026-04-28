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

    /** Default SSH server (must match the well-known fleet domain). */
    private const DEFAULT_SERVER = 'go.xpos.dev';

    /**
     * Path to the per-process known_hosts file pinning the xpos.dev
     * fleet's host key. Null when the caller is using a custom server
     * (pinning doesn't apply) or before start() runs.
     */
    private ?string $knownHostsPath = null;

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

        $args = $this->buildArgs();
        $this->process = new Process($args);
        $this->process->setTimeout(null);

        $outputBuffer = '';
        $error = null;

        $this->process->start(function ($type, $buffer) use (&$outputBuffer, &$error) {
            $outputBuffer .= $buffer;

            // Fire onOutput callback with raw text
            if ($this->onOutputCallback) {
                ($this->onOutputCallback)($buffer);
            }

            // Parse URL
            if (!$this->url) {
                $this->url = $this->parseUrl($outputBuffer);
            }

            // Parse expiry
            if (!$this->expiresAt) {
                $this->expiresAt = $this->parseExpiry($outputBuffer);
            }

            // Check for errors (don't throw from callback — set variable for poll loop)
            if (!$error) {
                $lines = explode("\n", $buffer);
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

        // Check for error from SSH output
        if ($error) {
            $this->killProcess();
            throw new \RuntimeException($error);
        }

        // Check if process died
        if (!$this->process->isRunning() && !$this->url) {
            $errorOutput = trim($this->process->getErrorOutput());
            throw new \RuntimeException(
                'Tunnel connection failed' . ($errorOutput ? ": {$errorOutput}" : '')
            );
        }

        // Timeout
        if (!$this->url) {
            $this->killProcess();
            throw new \RuntimeException('Tunnel connection timed out');
        }

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

        $this->connected = false;

        if ($this->process->isRunning()) {
            $this->process->stop(self::KILL_TIMEOUT);
        }

        $this->process = null;
        $this->cleanupHostKeys();

        if ($this->onCloseCallback) {
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

        if ($this->onCloseCallback) {
            ($this->onCloseCallback)($exitCode);
        }

        return $exitCode;
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
     * Build the SSH command arguments.
     *
     * When connecting to the official xpos.dev fleet (server matches
     * DEFAULT_SERVER), the SDK pins the SSH host key it fetched from
     * https://xpos.dev/.well-known/ssh-host-keys via a per-process
     * known_hosts file referenced by $this->knownHostsPath. For custom
     * servers $this->knownHostsPath stays null and the legacy accept-new
     * behaviour is used. The well-known URL is hard-coded to xpos.dev
     * and only valid for that fleet.
     */
    private function buildArgs(): array
    {
        $hostKeyOpts = $this->knownHostsPath
            ? [
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile=' . $this->knownHostsPath,
            ]
            : [
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'UserKnownHostsFile=~/.ssh/xpos_known_hosts',
            ];

        return array_merge(
            ['ssh', '-p', (string) $this->sshPort],
            $hostKeyOpts,
            [
                '-o', 'LogLevel=ERROR',
                '-o', 'ConnectTimeout=10',
                '-R', $this->buildRemoteForward(),
                $this->buildSshUser() . '@' . $this->server,
            ]
        );
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
     * Build the -R remote forward argument.
     */
    private function buildRemoteForward(): string
    {
        if ($this->domain) {
            return "{$this->domain}:80:{$this->host}:{$this->port}";
        }

        if ($this->subdomain) {
            return "{$this->subdomain}:80:{$this->host}:{$this->port}";
        }

        return "0:{$this->host}:{$this->port}";
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
