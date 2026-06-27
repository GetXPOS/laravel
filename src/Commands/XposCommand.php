<?php

namespace GetXPOS\Laravel\Commands;

use GetXPOS\Laravel\Support\ServeManager;
use GetXPOS\Laravel\XposTunnel;
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
        {--domain= : Custom domain (Business plan)}
        {--mode=http : Tunnel mode (http or tcp)}';

    /**
     * The console command description.
     */
    protected $description = 'Create an XPOS tunnel to expose your Laravel app publicly';

    /**
     * The XposTunnel instance.
     */
    protected ?XposTunnel $tunnel = null;

    /**
     * The serve process (if we started it).
     */
    protected ?Process $serveProcess = null;

    /**
     * Whether we started the serve process ourselves.
     */
    protected bool $weStartedServe = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->option('mode');

        // Validate mode
        if (!in_array($mode, ['http', 'tcp'], true)) {
            $this->error('  Mode must be "http" or "tcp"');
            return self::FAILURE;
        }

        // Validate mutually exclusive options
        if ($this->option('subdomain') && $this->option('domain')) {
            $this->error('  Cannot use --subdomain and --domain together');
            return self::FAILURE;
        }

        $token = XposTunnel::resolveToken($this->option('token'));

        // Subdomain/domain require authentication
        if (($this->option('subdomain') || $this->option('domain')) && !$token) {
            $this->error('  Reserved subdomains require authentication');
            $this->line('  <fg=gray>Set XPOS_TOKEN in .env or use --token=<your-token></>');
            $this->line('  <fg=gray>Get your token at</> <fg=cyan>https://xpos.dev/dashboard/tokens</>');
            return self::FAILURE;
        }

        // TCP mode requires authentication — server rejects anonymous TCP
        // tunnels, fail early with a useful message instead of timing out.
        if ($mode === 'tcp' && !$token) {
            $this->error('  TCP mode requires authentication');
            $this->line('  <fg=gray>Set XPOS_TOKEN in .env or use --token=<your-token></>');
            return self::FAILURE;
        }

        // TCP mode requires --port explicitly
        if ($mode === 'tcp' && !$this->option('port')) {
            $this->error('  TCP mode requires --port');
            $this->line('  <fg=gray>Example: php artisan xpos --mode=tcp --port=5432 --token=tk_xxx</>');
            return self::FAILURE;
        }

        $this->displayBanner($token, $mode);

        // Check if SSH is available
        if (!$this->sshExists()) {
            $this->error('  SSH client not found');
            $this->line('  <fg=gray>Please install OpenSSH client to use XPOS</>');
            return self::FAILURE;
        }

        // Determine port
        $serveManager = new ServeManager();
        $port = null;

        if ($mode === 'tcp') {
            // TCP mode: use --port directly, no auto-serve
            $port = (int) $this->option('port');
        } else {
            // HTTP mode: existing port resolution with auto-serve
            $port = $this->determinePort($serveManager);
            if ($port === null) {
                return self::FAILURE;
            }
        }

        // Create tunnel
        $this->newLine();
        $this->line('  <fg=gray>Creating tunnel to XPOS...</>');

        try {
            $this->tunnel = new XposTunnel([
                'port' => $port,
                'host' => $this->option('host'),
                'token' => $this->option('token'),
                'subdomain' => $this->option('subdomain'),
                'domain' => $this->option('domain'),
                'mode' => $mode,
            ]);
        } catch (\Throwable $e) {
            // M2: the constructor validates input (control/whitespace in
            // host/server, bad DNS) and throws \InvalidArgumentException, which
            // the narrow \RuntimeException catch around start() below would miss
            // — leaking the serve child started in determinePort() and orphaning
            // .xpos.pid. Catch here, clean up, and fail.
            $this->newLine();
            $this->error('  ' . $e->getMessage());
            $this->cleanup($serveManager);
            return self::FAILURE;
        }

        // Set output callback — filter lines, display errors/passthrough
        $this->tunnel->onOutput(function (string $buffer) {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $line = trim($line, "\r\n ");

                if (XposTunnel::shouldFilterLine($line)) {
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

        try {
            $this->tunnel->start();
        } catch (\RuntimeException $e) {
            // M2: surface ALL setup/connection failures. The previous prefix
            // filter ('Tunnel connection' / 'Tunnel is already') silently
            // swallowed pre-SSH setup exceptions (host-key pinning, ssh_config
            // write), leaving the user with a bare FAILURE and no message. The
            // SSH "Error: ..." stdout lines are printed separately by the
            // onOutput callback above, so this is the SDK's own exception
            // message and won't double-print them.
            $msg = $e->getMessage();
            if ($msg !== '') {
                $this->newLine();
                $this->error('  ' . $msg);
            }
            $this->cleanup($serveManager);
            return self::FAILURE;
        }

        // Display URL box
        $this->displayTunnelUrl($this->tunnel->url);

        // Display expiry in yellow
        if ($this->tunnel->expiresAt) {
            $this->line('  <fg=yellow>' . XposTunnel::formatExpiry($this->tunnel->expiresAt) . '</>');
        }

        // Keep running until user presses Ctrl+C or process ends
        $this->registerShutdownHandler($serveManager);
        $this->tunnel->wait();
        $this->cleanup($serveManager);

        return self::SUCCESS;
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
    protected function displayBanner(?string $token, string $mode = 'http'): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>XPOS Tunnel</>');
        $this->line('  <fg=gray>─────────────────────────────────────────</>');

        if ($token) {
            $this->line('  <fg=gray>Mode:</>       <fg=green>Authenticated</>');
        } else {
            $this->line('  <fg=gray>Mode:</>       <fg=yellow>Anonymous (3hr expiry)</>');
        }

        if ($mode === 'tcp') {
            $this->line('  <fg=gray>Type:</>       <fg=white>TCP</>');
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
        $portOption = $this->option('port');

        // If --no-serve, require an already running server
        if ($this->option('no-serve')) {
            $port = $portOption !== null ? (int) $portOption : (int) config('xpos.default_port', 8000);

            if (!$serveManager->isPortListening($port, $host)) {
                $this->error("  No server running on {$host}:{$port}");
                $this->line('  <fg=gray>Start your server first or remove --no-serve flag</>');
                return null;
            }

            $this->line("  <fg=green>✓</> Using existing server on <fg=white>http://{$host}:{$port}</>");
            return $port;
        }

        // Check if a tracked serve is already running for this project (C16:
        // host-aware). runningServe() cleans up a dead PID file and returns null.
        $serve = $serveManager->runningServe();
        if ($serve !== null) {
            if ($serve['host'] === $host && $serveManager->isPortListening($serve['port'], $host)) {
                // Reuse the live tracked serve on the matching host.
                // C17: an explicit --port that differs is overridden by the
                // running server — say so instead of silently dropping it.
                if ($portOption !== null && (int) $portOption !== $serve['port']) {
                    $this->line("  <fg=yellow>Note: a tracked dev server is already running on port {$serve['port']}; ignoring --port={$portOption}.</>");
                }
                $this->line("  <fg=green>✓</> Found running server on <fg=white>http://{$host}:{$serve['port']}</>");
                return $serve['port'];
            }

            if ($serve['host'] === $host) {
                // PID alive but not serving on the host — wedged. Clean up and
                // start fresh below.
                $serveManager->cleanup();
            } else {
                // C16: a live tracked serve on a DIFFERENT host. Overwriting the
                // PID file here would orphan it (cleanup() only unlinks the file,
                // it never stops the process). Warn and fail, mirroring the
                // --no-serve failure path.
                $this->error("  A tracked dev server is already live on {$serve['host']}:{$serve['port']}.");
                $this->line("  <fg=gray>Stop it, or re-run with --host={$serve['host']}.</>");
                return null;
            }
        }

        // Start a new serve process
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
     * Display the tunnel URL in a nice box.
     */
    protected function displayTunnelUrl(string $url): void
    {
        $this->newLine();

        $padding = 3;
        $urlLength = strlen($url);
        $boxWidth = max($urlLength + ($padding * 2), 40);
        $horizontalLine = str_repeat('─', $boxWidth);

        // Center the URL within the box
        $totalPadding = $boxWidth - $urlLength;
        $leftPadding = (int) floor($totalPadding / 2);
        $rightPadding = $totalPadding - $leftPadding;
        $leftSpace = str_repeat(' ', $leftPadding);
        $rightSpace = str_repeat(' ', $rightPadding);

        $this->line("  <fg=green>┌{$horizontalLine}┐</>");
        $this->line("  <fg=green>│</>{$leftSpace}<fg=white;options=bold>{$url}</>{$rightSpace}<fg=green>│</>");
        $this->line("  <fg=green>└{$horizontalLine}┘</>");

        $this->newLine();
        $this->line('  <fg=gray>Tunnel active. Press</> <fg=yellow>Ctrl+C</> <fg=gray>to stop.</>');
        $this->newLine();
    }

    /**
     * Clean up processes on exit.
     */
    protected function cleanup(ServeManager $serveManager): void
    {
        // Stop tunnel
        if ($this->tunnel) {
            $this->tunnel->close();
        }

        // Stop serve process only if we started it
        if ($this->weStartedServe && $this->serveProcess && $this->serveProcess->isRunning()) {
            $this->serveProcess->stop(3);
            $serveManager->cleanup();
        }
    }
}
