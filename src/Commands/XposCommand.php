<?php

namespace GetXPOS\Laravel\Commands;

use GetXPOS\Laravel\Support\ServeManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'xpos')]
class XposCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'xpos
        {--port= : Port for the development server}
        {--host=127.0.0.1 : Host to bind the development server to}
        {--no-serve : Skip starting artisan serve (use existing server)}
        {--token= : XPOS auth token (overrides XPOS_TOKEN env)}
        {--subdomain= : Reserved subdomain name (Pro plan)}
        {--domain= : Custom domain (Business plan)}';

    /**
     * The console command description.
     */
    protected $description = 'Create an XPOS tunnel to expose your Laravel app publicly';

    /**
     * The SSH tunnel process.
     */
    protected ?Process $tunnelProcess = null;

    /**
     * The serve process (if we started it).
     */
    protected ?Process $serveProcess = null;

    /**
     * Whether we started the serve process ourselves.
     */
    protected bool $weStartedServe = false;

    /**
     * The parsed public URL.
     */
    protected ?string $publicUrl = null;

    /**
     * The parsed expiry timestamp.
     */
    protected ?string $expiresAt = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Validate mutually exclusive options
        if ($this->option('subdomain') && $this->option('domain')) {
            $this->error('  Cannot use --subdomain and --domain together');
            return self::FAILURE;
        }

        $token = $this->resolveToken();

        // Subdomain/domain require authentication
        if (($this->option('subdomain') || $this->option('domain')) && !$token) {
            $this->error('  Reserved subdomains require authentication');
            $this->line('  <fg=gray>Set XPOS_TOKEN in .env or use --token=<your-token></>');
            $this->line('  <fg=gray>Get your token at</> <fg=cyan>https://xpos.dev/dashboard/tokens</>');
            return self::FAILURE;
        }

        $this->displayBanner($token);

        // Check if SSH is available
        if (!$this->sshExists()) {
            $this->error('  SSH client not found');
            $this->line('  <fg=gray>Please install OpenSSH client to use XPOS</>');
            return self::FAILURE;
        }

        $serveManager = new ServeManager();
        $port = $this->determinePort($serveManager);

        if ($port === null) {
            return self::FAILURE;
        }

        // Create the tunnel
        $this->newLine();
        $this->line('  <fg=gray>Creating tunnel to XPOS...</>');

        $tunnelResult = $this->createTunnel($port, $token);

        if ($tunnelResult !== self::SUCCESS) {
            $this->cleanup($serveManager);
            return $tunnelResult;
        }

        // Keep running until user presses Ctrl+C (Unix) or process ends (Windows)
        $this->registerShutdownHandler($serveManager);

        // Wait for tunnel process
        while ($this->tunnelProcess && $this->tunnelProcess->isRunning()) {
            usleep(100000); // 100ms
        }

        $this->cleanup($serveManager);

        return self::SUCCESS;
    }

    /**
     * Resolve the auth token from option or config.
     */
    protected function resolveToken(): ?string
    {
        $token = $this->option('token') ?? config('xpos.token');

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
     * Build the SSH username from token presence.
     */
    protected function buildSshUser(?string $token): string
    {
        return $token ?? 'x';
    }

    /**
     * Build the -R remote forward argument.
     */
    protected function buildRemoteForward(int $port, string $host): string
    {
        $domain = $this->option('domain');
        $subdomain = $this->option('subdomain');

        if ($domain) {
            return "{$domain}:80:{$host}:{$port}";
        }

        if ($subdomain) {
            return "{$subdomain}:80:{$host}:{$port}";
        }

        return "0:{$host}:{$port}";
    }

    /**
     * Register shutdown handler for graceful cleanup.
     */
    protected function registerShutdownHandler(ServeManager $serveManager): void
    {
        // Unix signals (Linux/macOS)
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal')) {
            if (defined('SIGINT')) {
                $this->trap([SIGINT, SIGTERM], function () use ($serveManager) {
                    $this->newLine();
                    $this->line('  <fg=yellow>Shutting down...</>');
                    $this->cleanup($serveManager);
                    exit(0);
                });
            }
        }

        // Fallback: register shutdown function for all platforms
        register_shutdown_function(function () use ($serveManager) {
            $this->cleanup($serveManager);
        });
    }

    /**
     * Check if SSH client is available.
     */
    protected function sshExists(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['where', 'ssh']);
        } else {
            $process = new Process(['which', 'ssh']);
        }

        $process->run();

        return $process->isSuccessful() && !empty(trim($process->getOutput()));
    }

    /**
     * Display the XPOS banner.
     */
    protected function displayBanner(?string $token): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>XPOS Tunnel</>');
        $this->line('  <fg=gray>─────────────────────────────────────────</>');

        if ($token) {
            $this->line('  <fg=gray>Mode:</>       <fg=green>Authenticated</>');
        } else {
            $this->line('  <fg=gray>Mode:</>       <fg=yellow>Anonymous (3hr expiry)</>');
        }

        if ($this->option('subdomain')) {
            $this->line('  <fg=gray>Subdomain:</>  <fg=white>' . $this->option('subdomain') . '</>');
        }

        if ($this->option('domain')) {
            $this->line('  <fg=gray>Domain:</>     <fg=white>' . $this->option('domain') . '</>');
        }
    }

    /**
     * Determine which port to use for the tunnel.
     */
    protected function determinePort(ServeManager $serveManager): ?int
    {
        $host = $this->option('host');

        // If --no-serve, require an already running server
        if ($this->option('no-serve')) {
            $portOption = $this->option('port');
            $port = $portOption !== null ? (int) $portOption : (int) config('xpos.default_port', 8000);

            if (!$serveManager->isPortListening($port, $host)) {
                $this->error("  No server running on {$host}:{$port}");
                $this->line('  <fg=gray>Start your server first or remove --no-serve flag</>');
                return null;
            }

            $this->line("  <fg=green>✓</> Using existing server on <fg=white>http://{$host}:{$port}</>");
            return $port;
        }

        // Check if serve is already running for this project
        $existingPort = $serveManager->getRunningPort();
        if ($existingPort !== null) {
            $this->line("  <fg=green>✓</> Found running server on <fg=white>http://{$host}:{$existingPort}</>");
            return $existingPort;
        }

        // Start a new serve process
        $portOption = $this->option('port');
        $defaultPort = $portOption !== null ? (int) $portOption : (int) config('xpos.default_port', 8000);

        try {
            $this->line('  <fg=gray>Starting development server...</>');

            $result = $serveManager->startServe($defaultPort, $host);

            $this->serveProcess = $result['process'];
            $this->weStartedServe = true;

            $this->line("  <fg=green>✓</> Server running on <fg=white>http://{$host}:{$result['port']}</>");

            return $result['port'];
        } catch (\RuntimeException $e) {
            $this->error('  ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create the SSH tunnel to XPOS.
     */
    protected function createTunnel(int $port, ?string $token): int
    {
        $server = config('xpos.server', 'go.xpos.dev');
        $sshPort = config('xpos.ssh_port', 443);
        $sshUser = $this->buildSshUser($token);
        $host = $this->option('host');
        $remoteForward = $this->buildRemoteForward($port, $host);

        $command = [
            'ssh',
            '-p', (string) $sshPort,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'LogLevel=ERROR',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
            '-R', $remoteForward,
            "{$sshUser}@{$server}",
        ];

        $this->tunnelProcess = new Process($command);
        $this->tunnelProcess->setTimeout(null);

        $urlDisplayed = false;
        $outputBuffer = '';

        $this->tunnelProcess->start(function ($type, $buffer) use (&$urlDisplayed, &$outputBuffer) {
            $outputBuffer .= $buffer;

            // Parse HTTPS URL from structured output
            if (!$this->publicUrl && preg_match('/HTTPS:\s+(https:\/\/\S+)/i', $outputBuffer, $matches)) {
                $this->publicUrl = rtrim($matches[1], "\r\n");
            }

            // Fallback: parse HTTP URL
            if (!$this->publicUrl && preg_match('/HTTP:\s+(https?:\/\/\S+)/i', $outputBuffer, $matches)) {
                $this->publicUrl = rtrim($matches[1], "\r\n");
            }

            // Parse expiry
            if (!$this->expiresAt && preg_match('/Expires:\s+(\S+)/', $outputBuffer, $matches)) {
                $this->expiresAt = rtrim($matches[1], "\r\n");
            }

            // Display URL once parsed
            if ($this->publicUrl && !$urlDisplayed) {
                $urlDisplayed = true;
                $this->displayTunnelUrl($this->publicUrl);

                if ($this->expiresAt) {
                    $this->line('  ' . $this->formatExpiry($this->expiresAt));
                }
            }

            // Filter and display output lines
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $line = trim($line, "\r\n ");

                if (empty($line)) {
                    continue;
                }

                // Suppress lines we display ourselves
                if (str_contains($line, 'Tunnel created')
                    || str_contains($line, 'HTTP:')
                    || str_contains($line, 'HTTPS:')
                    || str_contains($line, 'Press Ctrl+C')
                    || str_contains($line, 'Expires:')
                ) {
                    continue;
                }

                // Show errors in red
                if (preg_match('/^Error:\s*(.+)/', $line, $errorMatch)) {
                    $this->error("  {$errorMatch[1]}");
                    continue;
                }

                // Pass through unexpected output in gray
                $this->line("  <fg=gray>{$line}</>");
            }
        });

        // Wait for URL to appear (max 15 seconds)
        $waitedMs = 0;
        $maxWaitMs = 15000;
        while (!$this->publicUrl && $waitedMs < $maxWaitMs && $this->tunnelProcess->isRunning()) {
            usleep(100000); // 100ms
            $waitedMs += 100;

            if ($waitedMs > 0 && $waitedMs % 2000 === 0 && !$urlDisplayed) {
                $this->output->write('.');
            }
        }

        if (!$this->tunnelProcess->isRunning()) {
            $this->newLine();
            $this->error('  Tunnel connection failed');
            $errorOutput = trim($this->tunnelProcess->getErrorOutput());
            if (!empty($errorOutput)) {
                $this->line("  <fg=gray>{$errorOutput}</>");
            }
            return self::FAILURE;
        }

        // Show expiry if it arrived after URL was already displayed
        if ($this->publicUrl && $urlDisplayed && $this->expiresAt) {
            // Already shown above in the callback
        }

        if (!$this->publicUrl) {
            $this->newLine();
            $this->warn('  <fg=yellow>Tunnel connected but URL not detected</>');
            $this->line('  <fg=gray>Check the output above for your URL</>');
        }

        return self::SUCCESS;
    }

    /**
     * Display the tunnel URL in a nice box.
     */
    protected function displayTunnelUrl(string $url): void
    {
        $this->newLine();

        $padding = 3;
        $urlLength = strlen($url);
        $boxWidth = $urlLength + ($padding * 2);
        $horizontalLine = str_repeat('─', $boxWidth);
        $emptySpace = str_repeat(' ', $padding);

        $this->line("  <fg=green>┌{$horizontalLine}┐</>");
        $this->line("  <fg=green>│</>{$emptySpace}<fg=white;options=bold>{$url}</>{$emptySpace}<fg=green>│</>");
        $this->line("  <fg=green>└{$horizontalLine}┘</>");

        $this->newLine();
        $this->line('  <fg=gray>Tunnel active. Press</> <fg=yellow>Ctrl+C</> <fg=gray>to stop.</>');
        $this->newLine();
    }

    /**
     * Format an RFC3339 expiry timestamp into a human-readable countdown.
     */
    protected function formatExpiry(string $rfc3339): string
    {
        try {
            $expires = new \DateTimeImmutable($rfc3339);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $diff = $now->diff($expires);

            $parts = [];
            if ($diff->h > 0 || $diff->days > 0) {
                $totalHours = ($diff->days * 24) + $diff->h;
                $parts[] = "{$totalHours}h";
            }
            if ($diff->i > 0) {
                $parts[] = "{$diff->i}m";
            }

            $countdown = implode(' ', $parts) ?: '< 1m';
            $utcTime = $expires->setTimezone(new \DateTimeZone('UTC'))->format('H:i');

            return "<fg=gray>Expires in {$countdown} ({$utcTime} UTC)</>";
        } catch (\Throwable) {
            return "<fg=gray>Expires: {$rfc3339}</>";
        }
    }

    /**
     * Clean up processes on exit.
     */
    protected function cleanup(ServeManager $serveManager): void
    {
        // Stop tunnel process
        if ($this->tunnelProcess && $this->tunnelProcess->isRunning()) {
            $this->tunnelProcess->stop(3);
        }

        // Stop serve process only if we started it
        if ($this->weStartedServe && $this->serveProcess && $this->serveProcess->isRunning()) {
            $this->serveProcess->stop(3);
            $serveManager->cleanup();
        }
    }
}
