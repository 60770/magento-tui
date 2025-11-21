<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\DeploymentConfig;
use Symfony\Component\Process\Process;

/**
 * Service for database dump and restore operations
 */
class DatabaseService
{
    private ?Process $currentProcess = null;
    private string $processOutput = '';
    private bool $isProcessing = false;
    private string $currentOperation = '';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Get database connection info
     *
     * @return array
     */
    public function getDatabaseInfo(): array
    {
        try {
            $dbConfig = $this->deploymentConfig->get('db/connection/default');

            return [
                'host' => $dbConfig['host'] ?? 'localhost',
                'dbname' => $dbConfig['dbname'] ?? '',
                'username' => $dbConfig['username'] ?? '',
                'password' => $dbConfig['password'] ?? '',
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Create database dump
     *
     * @param string $outputPath
     * @param bool $gzip
     * @return void
     */
    public function createDump(string $outputPath, bool $gzip = true): void
    {
        if ($this->isProcessing) {
            return;
        }

        $dbInfo = $this->getDatabaseInfo();

        if (empty($dbInfo['dbname'])) {
            return;
        }

        $this->processOutput = '';
        $this->isProcessing = true;
        $this->currentOperation = 'dump';

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Build mysqldump command
        $command = sprintf(
            'mysqldump -h %s -u %s -p%s %s --single-transaction --quick --lock-tables=false',
            escapeshellarg($dbInfo['host']),
            escapeshellarg($dbInfo['username']),
            escapeshellarg($dbInfo['password']),
            escapeshellarg($dbInfo['dbname'])
        );

        if ($gzip) {
            $command .= ' | gzip > ' . escapeshellarg($outputPath);
        } else {
            $command .= ' > ' . escapeshellarg($outputPath);
        }

        $this->currentProcess = Process::fromShellCommandline($command, BP, null, null, 3600); // 1 hour timeout
        $this->currentProcess->start();
    }

    /**
     * Restore database from dump
     *
     * @param string $dumpPath
     * @return void
     */
    public function restoreDump(string $dumpPath): void
    {
        if ($this->isProcessing) {
            return;
        }

        if (!file_exists($dumpPath)) {
            return;
        }

        $dbInfo = $this->getDatabaseInfo();

        if (empty($dbInfo['dbname'])) {
            return;
        }

        $this->processOutput = '';
        $this->isProcessing = true;
        $this->currentOperation = 'restore';

        // Check if file is gzipped
        $isGzip = str_ends_with($dumpPath, '.gz');

        // Build mysql command
        if ($isGzip) {
            $command = sprintf(
                'gunzip < %s | mysql -h %s -u %s -p%s %s',
                escapeshellarg($dumpPath),
                escapeshellarg($dbInfo['host']),
                escapeshellarg($dbInfo['username']),
                escapeshellarg($dbInfo['password']),
                escapeshellarg($dbInfo['dbname'])
            );
        } else {
            $command = sprintf(
                'mysql -h %s -u %s -p%s %s < %s',
                escapeshellarg($dbInfo['host']),
                escapeshellarg($dbInfo['username']),
                escapeshellarg($dbInfo['password']),
                escapeshellarg($dbInfo['dbname']),
                escapeshellarg($dumpPath)
            );
        }

        $this->currentProcess = Process::fromShellCommandline($command, BP, null, null, 3600); // 1 hour timeout
        $this->currentProcess->start();
    }

    /**
     * Check if operation is in progress
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        if ($this->currentProcess && $this->currentProcess->isRunning()) {
            return true;
        }

        if ($this->currentProcess && !$this->currentProcess->isRunning() && $this->isProcessing) {
            // Process just finished
            $this->processOutput .= $this->currentProcess->getOutput();
            $this->processOutput .= $this->currentProcess->getErrorOutput();
            $this->isProcessing = false;
        }

        return $this->isProcessing;
    }

    /**
     * Get current operation
     *
     * @return string
     */
    public function getCurrentOperation(): string
    {
        return $this->currentOperation;
    }

    /**
     * Get operation output
     *
     * @return string
     */
    public function getOutput(): string
    {
        if ($this->currentProcess && $this->currentProcess->isRunning()) {
            // Get incremental output
            $output = $this->currentProcess->getIncrementalOutput();
            $errorOutput = $this->currentProcess->getIncrementalErrorOutput();

            if ($output) {
                $this->processOutput .= $output;
            }
            if ($errorOutput) {
                $this->processOutput .= $errorOutput;
            }
        }

        return $this->processOutput;
    }

    /**
     * Get process exit code (only when finished)
     *
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        if ($this->currentProcess && !$this->currentProcess->isRunning()) {
            return $this->currentProcess->getExitCode();
        }
        return null;
    }

    /**
     * Get approximate progress percentage
     *
     * @param string $filePath
     * @return int
     */
    public function getProgress(string $filePath): int
    {
        if (!$this->isProcessing || !file_exists($filePath)) {
            return 0;
        }

        // For dump: estimate based on file size growth
        if ($this->currentOperation === 'dump') {
            $currentSize = filesize($filePath);
            // Rough estimate: assume 100MB for average database
            $estimatedTotal = 100 * 1024 * 1024;
            $progress = min(95, ($currentSize / $estimatedTotal) * 100);
            return (int)$progress;
        }

        // For restore: harder to estimate, return 50% if running
        if ($this->currentOperation === 'restore' && $this->isProcessing) {
            return 50;
        }

        return 0;
    }

    /**
     * Clear operation state
     *
     * @return void
     */
    public function clear(): void
    {
        $this->processOutput = '';
        $this->currentProcess = null;
        $this->isProcessing = false;
        $this->currentOperation = '';
    }

    /**
     * List available backup files
     *
     * @return array
     */
    public function listBackups(): array
    {
        $backupDir = BP . '/var/backups';
        $backups = [];

        if (!is_dir($backupDir)) {
            return [];
        }

        $files = scandir($backupDir);
        foreach ($files as $file) {
            if (preg_match('/\.sql(\.gz)?$/', $file)) {
                $fullPath = $backupDir . '/' . $file;
                $backups[] = [
                    'filename' => $file,
                    'path' => $fullPath,
                    'size' => filesize($fullPath),
                    'date' => filemtime($fullPath),
                ];
            }
        }

        // Sort by date descending
        usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);

        return $backups;
    }
}
