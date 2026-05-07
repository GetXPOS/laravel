<?php

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
     * Cached serving status.
     */
    protected ?bool $cachedServingStatus = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path();
        $this->pidFile = $this->basePath . '/.xpos.pid';
    }

    /**
     * Check if artisan serve is currently running for this project.
     */
    public function isServing(): bool
    {
        // Return cached result if available
        if ($this->cachedServingStatus !== null) {
            return $this->cachedServingStatus;
        }

        $data = $this->readPidFile();

        if (!$data) {
            return $this->cachedServingStatus = false;
        }

        // Verify PID is still alive. Cast to int explicitly so a malformed
        // PID file ("5; rm -rf /" or non-numeric) coerces to a safe integer
        // before reaching shell-using fallbacks. Silences PHP 8.3+
        // non-numeric coercion deprecation warnings as well.
        if (!$this->isProcessRunning((int)($data['pid'] ?? 0))) {
            $this->cleanup();
            return $this->cachedServingStatus = false;
        }

        // Verify port is actually listening
        if (!$this->isPortListening($data['port'])) {
            $this->cleanup();
            return $this->cachedServingStatus = false;
        }

        return $this->cachedServingStatus = true;
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
     * Get the port if serve is running, null otherwise.
     * Combined method to avoid double-checking.
     */
    public function getRunningPort(): ?int
    {
        if (!$this->isServing()) {
            return null;
        }

        return $this->getPort();
    }

    /**
     * Start a new artisan serve process in the background.
     *
     * @return array{port: int, pid: int, process: Process}
     */
    public function startServe(int $port = 8000, string $host = '127.0.0.1'): array
    {
        // Find available port starting from the given port
        $port = $this->findAvailablePort($port);

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

        $pid = $process->getPid();

        // Write PID file
        $this->writePidFile($port, $pid);

        // Invalidate cache
        $this->cachedServingStatus = true;
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
    public function findAvailablePort(int $startPort = 8000, int $maxTries = 10): int
    {
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $startPort + $i;

            if (!$this->isPortListening($port)) {
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
     * Read the PID file.
     *
     * @return array{port: int, pid: int, started: int}|null
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

        // Cache the data
        $this->cachedPidData = $data;

        return $data;
    }

    /**
     * Write the PID file.
     */
    public function writePidFile(int $port, int $pid): void
    {
        $data = [
            'port' => $port,
            'pid' => $pid,
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
        $this->cachedServingStatus = null;
    }

    /**
     * Get the path to the PID file.
     */
    public function getPidFilePath(): string
    {
        return $this->pidFile;
    }
}
