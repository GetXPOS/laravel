<?php

declare(strict_types=1);

namespace GetXPOS\Laravel\Support;

use Symfony\Component\Process\Process;

class ServeManager
{
    /**
     * Path to the PID file that tracks this project's serve process.
     */
    protected string $pidFile;

    /**
     * The base path of the Laravel application.
     */
    protected string $basePath;

    /**
     * Cached PID file data.
     */
    protected ?array $cachedPidData = null;

    /**
     * Cached serving status, keyed by requested host (C16). A bare bool memo
     * would return the first probed host's result for every later host.
     *
     * @var array<string, bool>
     */
    protected array $cachedServingStatus = [];

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path();
        $this->pidFile = $this->basePath . '/.xpos.pid';
    }

    /**
     * Check if artisan serve is currently running for this project ON the given
     * host. The tracked serve must match the requested host AND be live on it
     * (C16) — a serve bound to a different interface is NOT a match for $host.
     */
    public function isServing(string $host = '127.0.0.1'): bool
    {
        // Return cached result if available (memo is per-host).
        if (array_key_exists($host, $this->cachedServingStatus)) {
            return $this->cachedServingStatus[$host];
        }

        $data = $this->readPidFile();

        if (!$data) {
            return $this->cachedServingStatus[$host] = false;
        }

        // Verify PID is still alive. Cast to int explicitly so a malformed
        // PID file ("5; rm -rf /" or non-numeric) coerces to a safe integer
        // before reaching shell-using fallbacks. Silences PHP 8.3+
        // non-numeric coercion deprecation warnings as well.
        if (!$this->isProcessRunning((int)($data['pid'] ?? 0))) {
            $this->cleanup();
            return $this->cachedServingStatus[$host] = false;
        }

        // The tracked serve must be for the requested host. A legacy PID file
        // (written before host was persisted) has no 'host' — treat it as the
        // loopback default so an upgrade reuses correctly against --host=127.0.0.1.
        $storedHost = isset($data['host']) ? (string)$data['host'] : '127.0.0.1';
        if ($storedHost !== $host) {
            return $this->cachedServingStatus[$host] = false;
        }

        // Verify port is actually listening on that host.
        if (!$this->isPortListening((int)$data['port'], $host)) {
            $this->cleanup();
            return $this->cachedServingStatus[$host] = false;
        }

        return $this->cachedServingStatus[$host] = true;
    }

    /**
     * Get the port being used by the running serve process.
     * Returns null if not serving.
     */
    public function getPort(): ?int
    {
        $data = $this->readPidFile();
        return $data['port'] ?? null;
    }

    /**
     * Get the port if serve is running on $host, null otherwise.
     */
    public function getRunningPort(string $host = '127.0.0.1'): ?int
    {
        if (!$this->isServing($host)) {
            return null;
        }

        return $this->getPort();
    }

    /**
     * Return the tracked serve's [host, port] when its PID is alive, regardless
     * of whether the host matches the caller's request — so the caller can
     * decide between reuse (matching host), a wedged-serve restart (matching
     * host but dead port), or a warn-and-fail (live serve on a different host,
     * which must NOT be orphaned). Cleans up a dead PID file and returns null.
     *
     * @return array{host: string, port: int}|null
     */
    public function runningServe(): ?array
    {
        $data = $this->readPidFile();
        if (!$data) {
            return null;
        }
        if (!$this->isProcessRunning((int)($data['pid'] ?? 0))) {
            $this->cleanup();
            return null;
        }
        $storedHost = isset($data['host']) ? (string)$data['host'] : '127.0.0.1';
        return ['host' => $storedHost, 'port' => (int)$data['port']];
    }

    /**
     * Start a new artisan serve process in the background.
     *
     * @return array{port: int, pid: int, process: Process}
     */
    public function startServe(int $port = 8000, string $host = '127.0.0.1'): array
    {
        // Find available port starting from the given port — probe the SAME
        // host the serve will bind (C16), not the loopback default.
        $port = $this->findAvailablePort($port, 10, $host);

        // Build the command
        $phpBinary = PHP_BINARY;
        $artisan = $this->basePath . '/artisan';

        // Verify artisan file exists
        if (!file_exists($artisan)) {
            throw new \RuntimeException(
                'artisan file not found. Are you in a Laravel project directory?'
            );
        }

        $process = new Process([
            $phpBinary,
            $artisan,
            'serve',
            '--host=' . $host,
            '--port=' . $port,
            '--no-reload',
        ], $this->basePath);

        // Start in background (don't wait for completion)
        $process->setTimeout(null);
        $process->start();

        // Wait briefly for server to start
        usleep(800000); // 0.8 seconds

        // Verify it started successfully
        if (!$process->isRunning()) {
            $errorOutput = $process->getErrorOutput();
            throw new \RuntimeException(
                'Failed to start development server' .
                ($errorOutput ? ': ' . trim($errorOutput) : '')
            );
        }

        // getPid() is ?int (null if the process died in the microsecond window
        // since the isRunning() check above). Cast to int so writePidFile's
        // int $pid param doesn't raise a TypeError under declare(strict_types=1);
        // a null coerces to 0, which isProcessRunning() later rejects (pid <= 0)
        // and cleans up — the same graceful path as before strict types.
        $pid = (int) $process->getPid();

        // Write PID file (persist the bind host so reuse/probing keys off the
        // same interface).
        $this->writePidFile($port, $pid, $host);

        // Invalidate cache — memo this host's now-true status, drop the rest.
        $this->cachedServingStatus = [$host => true];
        $this->cachedPidData = null;

        return [
            'port' => $port,
            'pid' => $pid,
            'process' => $process,
        ];
    }

    /**
     * Find an available port starting from the given port.
     */
    public function findAvailablePort(int $startPort = 8000, int $maxTries = 10, string $host = '127.0.0.1'): int
    {
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $startPort + $i;

            // Probe the SAME host the serve will bind (C16) — a loopback-only
            // probe gives false "available" verdicts for a specific interface.
            if (!$this->isPortListening($port, $host)) {
                return $port;
            }
        }

        throw new \RuntimeException(
            "Could not find an available port. Tried ports {$startPort} to " . ($startPort + $maxTries - 1)
        );
    }

    /**
     * Check if a port is currently listening.
     */
    public function isPortListening(int $port, string $host = '127.0.0.1'): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.5);

        if ($connection) {
            fclose($connection);
            return true;
        }

        return false;
    }

    /**
     * Check if a process is running by PID.
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // POSIX systems (Linux, macOS)
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Fallback: check /proc filesystem (Linux)
        if (is_dir("/proc/{$pid}")) {
            return true;
        }

        // Windows fallback
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>&1", $output, $exitCode);
            return $exitCode === 0 && count($output) > 1;
        }

        // Generic fallback: try kill -0
        exec("kill -0 {$pid} 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Read the PID file. `host` is absent in legacy (pre-host) PID files;
     * callers default it to '127.0.0.1'.
     *
     * @return array{port: int, pid: int, started: int, host?: string}|null
     */
    public function readPidFile(): ?array
    {
        // Return cached data if available
        if ($this->cachedPidData !== null) {
            return $this->cachedPidData;
        }

        if (!file_exists($this->pidFile)) {
            return null;
        }

        $content = @file_get_contents($this->pidFile);

        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['port'], $data['pid'])) {
            return null;
        }

        // Normalise to int up-front. Under declare(strict_types=1) a non-int
        // port/pid (a hand-edited or corrupted PID file holding a string like
        // "8000" or "5; rm -rf /") would raise a TypeError the moment it reached
        // the int-typed params/returns below (isPortListening(int $port),
        // getPort(): ?int, isProcessRunning(int $pid)). Casting here coerces a
        // malformed value to a safe integer once, preserving the existing
        // graceful "treat as not-serving and clean up" behaviour. (int) of a
        // non-numeric string is 0, which the downstream guards reject.
        $data['port'] = (int) $data['port'];
        $data['pid'] = (int) $data['pid'];

        // Cache the data
        $this->cachedPidData = $data;

        return $data;
    }

    /**
     * Write the PID file.
     */
    public function writePidFile(int $port, int $pid, string $host = '127.0.0.1'): void
    {
        $data = [
            'port' => $port,
            'pid' => $pid,
            'host' => $host,
            'started' => time(),
        ];

        file_put_contents($this->pidFile, json_encode($data, JSON_PRETTY_PRINT));

        // Restrict to owner-only on POSIX systems so another local user can't
        // plant a malicious PID for the next isProcessRunning() call to act on.
        // Silenced because the file may not exist (write failure) or chmod may
        // not be honoured on the host filesystem.
        @chmod($this->pidFile, 0o600);

        // Invalidate cache
        $this->cachedPidData = $data;
    }

    /**
     * Clean up the PID file.
     */
    public function cleanup(): void
    {
        if (file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }

        // Invalidate cache
        $this->cachedPidData = null;
        $this->cachedServingStatus = [];
    }

    /**
     * Get the path to the PID file.
     */
    public function getPidFilePath(): string
    {
        return $this->pidFile;
    }
}
