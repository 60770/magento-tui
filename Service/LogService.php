<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Process\Process;

/**
 * Service for managing and monitoring log files
 */
class LogService
{
    private const LOG_LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private ?Process $tailProcess = null;
    private string $tailOutput = '';
    private array $includeFilters = [];
    private array $excludeFilters = [];
    private bool $isTailing = false;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Get list of available log files
     *
     * @return array
     */
    public function getAvailableLogFiles(): array
    {
        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $logDir = $varDirectory->getAbsolutePath('log');
        $logFiles = [];

        if (is_dir($logDir)) {
            $files = scandir($logDir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $fullPath = $logDir . DIRECTORY_SEPARATOR . $file;
                    $logFiles[] = [
                        'name' => $file,
                        'path' => $fullPath,
                        'size' => filesize($fullPath),
                        'modified' => filemtime($fullPath)
                    ];
                }
            }
        }

        return $logFiles;
    }

    /**
     * Read log file with optional filtering
     *
     * @param string $filename
     * @param int $lines
     * @param string|null $level
     * @return array
     */
    public function readLogFile(string $filename, int $lines = 100, ?string $level = null): array
    {
        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $logDir = $varDirectory->getAbsolutePath('log');
        $filepath = $logDir . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filepath)) {
            return [];
        }

        $logEntries = $this->tailFile($filepath, $lines);
        
        if ($level !== null) {
            $logEntries = $this->filterByLevel($logEntries, $level);
        }

        return $logEntries;
    }

    /**
     * Tail a file (read last N lines)
     *
     * @param string $filepath
     * @param int $lines
     * @return array
     */
    private function tailFile(string $filepath, int $lines): array
    {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }

        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = ' ';
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) {
                break;
            }
        }

        fclose($handle);
        return array_reverse($text);
    }

    /**
     * Filter log entries by level
     *
     * @param array $entries
     * @param string $level
     * @return array
     */
    private function filterByLevel(array $entries, string $level): array
    {
        return array_filter($entries, function ($entry) use ($level) {
            return stripos($entry, $level) !== false;
        });
    }

    /**
     * Parse log entry to extract components
     *
     * @param string $entry
     * @return array
     */
    public function parseLogEntry(string $entry): array
    {
        // Pattern: [2024-01-01 12:00:00] main.ERROR: message
        $pattern = '/\[([^\]]+)\]\s+(\w+)\.(\w+):\s+(.+)/';
        
        if (preg_match($pattern, $entry, $matches)) {
            return [
                'timestamp' => $matches[1] ?? '',
                'channel' => $matches[2] ?? '',
                'level' => $matches[3] ?? '',
                'message' => $matches[4] ?? '',
                'raw' => $entry
            ];
        }

        return [
            'timestamp' => '',
            'channel' => '',
            'level' => '',
            'message' => $entry,
            'raw' => $entry
        ];
    }

    /**
     * Get available log levels
     *
     * @return array
     */
    public function getLogLevels(): array
    {
        return self::LOG_LEVELS;
    }

    /**
     * Start tailing a log file in background
     *
     * @param string $filename
     * @param int $lines Number of initial lines to show
     * @return void
     */
    public function startTail(string $filename, int $lines = 50): void
    {
        if ($this->isTailing) {
            return;
        }

        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $logDir = $varDirectory->getAbsolutePath('log');
        $filepath = $logDir . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filepath)) {
            return;
        }

        $this->tailOutput = '';
        $this->isTailing = true;

        // Use tail -f with initial lines
        $command = sprintf('tail -n %d -f %s', $lines, escapeshellarg($filepath));
        $this->tailProcess = Process::fromShellCommandline($command, null, null, null, null);
        $this->tailProcess->start();
    }

    /**
     * Stop tailing
     *
     * @return void
     */
    public function stopTail(): void
    {
        if ($this->tailProcess && $this->tailProcess->isRunning()) {
            $this->tailProcess->stop();
        }
        $this->tailProcess = null;
        $this->isTailing = false;
    }

    /**
     * Check if tailing is active
     *
     * @return bool
     */
    public function isTailing(): bool
    {
        if ($this->tailProcess && !$this->tailProcess->isRunning()) {
            $this->isTailing = false;
        }
        return $this->isTailing;
    }

    /**
     * Get tail output with filters applied
     *
     * @return array
     */
    public function getTailOutput(): array
    {
        if (!$this->tailProcess || !$this->tailProcess->isRunning()) {
            return explode("\n", trim($this->tailOutput));
        }

        // Get incremental output
        $newOutput = $this->tailProcess->getIncrementalOutput();
        if ($newOutput) {
            $this->tailOutput .= $newOutput;
        }

        // Apply filters
        $lines = explode("\n", trim($this->tailOutput));
        return $this->applyFilters($lines);
    }

    /**
     * Apply include/exclude filters to lines
     *
     * @param array $lines
     * @return array
     */
    private function applyFilters(array $lines): array
    {
        if (empty($this->includeFilters) && empty($this->excludeFilters)) {
            return $lines;
        }

        $filtered = [];
        foreach ($lines as $line) {
            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Apply exclude filters first
            $excluded = false;
            foreach ($this->excludeFilters as $pattern) {
                if (stripos($line, $pattern) !== false) {
                    $excluded = true;
                    break;
                }
            }

            if ($excluded) {
                continue;
            }

            // Apply include filters
            if (!empty($this->includeFilters)) {
                $included = false;
                foreach ($this->includeFilters as $pattern) {
                    if (stripos($line, $pattern) !== false) {
                        $included = true;
                        break;
                    }
                }
                if (!$included) {
                    continue;
                }
            }

            $filtered[] = $line;
        }

        return $filtered;
    }

    /**
     * Add include filter
     *
     * @param string $pattern
     * @return void
     */
    public function addIncludeFilter(string $pattern): void
    {
        if (!in_array($pattern, $this->includeFilters)) {
            $this->includeFilters[] = $pattern;
        }
    }

    /**
     * Add exclude filter
     *
     * @param string $pattern
     * @return void
     */
    public function addExcludeFilter(string $pattern): void
    {
        if (!in_array($pattern, $this->excludeFilters)) {
            $this->excludeFilters[] = $pattern;
        }
    }

    /**
     * Remove include filter
     *
     * @param string $pattern
     * @return void
     */
    public function removeIncludeFilter(string $pattern): void
    {
        $this->includeFilters = array_values(array_diff($this->includeFilters, [$pattern]));
    }

    /**
     * Remove exclude filter
     *
     * @param string $pattern
     * @return void
     */
    public function removeExcludeFilter(string $pattern): void
    {
        $this->excludeFilters = array_values(array_diff($this->excludeFilters, [$pattern]));
    }

    /**
     * Get include filters
     *
     * @return array
     */
    public function getIncludeFilters(): array
    {
        return $this->includeFilters;
    }

    /**
     * Get exclude filters
     *
     * @return array
     */
    public function getExcludeFilters(): array
    {
        return $this->excludeFilters;
    }

    /**
     * Clear all filters
     *
     * @return void
     */
    public function clearFilters(): void
    {
        $this->includeFilters = [];
        $this->excludeFilters = [];
    }

    /**
     * Clear tail output buffer
     *
     * @return void
     */
    public function clearTailOutput(): void
    {
        $this->tailOutput = '';
    }

    /**
     * Search for a pattern across all log files (including gzipped and subdirectories)
     *
     * @param string $pattern
     * @param int $maxResults
     * @return array
     */
    public function searchAllLogs(string $pattern, int $maxResults = 100): array
    {
        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $logDir = $varDirectory->getAbsolutePath('log');

        $results = [];
        $count = 0;

        // Search recursively
        $this->searchDirectory($logDir, $pattern, $results, $count, $maxResults, $logDir);

        return $results;
    }

    /**
     * Search directory recursively for log files
     *
     * @param string $dir
     * @param string $pattern
     * @param array $results
     * @param int $count
     * @param int $maxResults
     * @param string $baseDir
     * @return void
     */
    private function searchDirectory(string $dir, string $pattern, array &$results, int &$count, int $maxResults, string $baseDir): void
    {
        if ($count >= $maxResults || !is_dir($dir)) {
            return;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                // Recursively search subdirectories
                $this->searchDirectory($fullPath, $pattern, $results, $count, $maxResults, $baseDir);
            } elseif (is_file($fullPath)) {
                // Check if it's a log file (*.log or *.log.gz)
                if (preg_match('/\.log(\.gz)?$/', $file)) {
                    $this->searchFile($fullPath, $pattern, $results, $count, $maxResults, $baseDir);
                }
            }

            if ($count >= $maxResults) {
                break;
            }
        }
    }

    /**
     * Search a single file (handles both regular and gzipped files)
     *
     * @param string $filepath
     * @param string $pattern
     * @param array $results
     * @param int $count
     * @param int $maxResults
     * @param string $baseDir
     * @return void
     */
    private function searchFile(string $filepath, string $pattern, array &$results, int &$count, int $maxResults, string $baseDir): void
    {
        if ($count >= $maxResults) {
            return;
        }

        try {
            $isGzip = str_ends_with($filepath, '.gz');
            $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $filepath);

            if ($isGzip) {
                // Read gzipped file
                $handle = gzopen($filepath, 'r');
                if (!$handle) {
                    return;
                }

                $lineNumber = 0;
                while (!gzeof($handle) && $count < $maxResults) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }

                    $lineNumber++;

                    if (stripos($line, $pattern) !== false) {
                        $results[] = [
                            'file' => $relativePath,
                            'path' => $filepath,
                            'line_number' => $lineNumber,
                            'line' => trim($line),
                            'is_gzip' => true
                        ];
                        $count++;
                    }
                }

                gzclose($handle);
            } else {
                // Read regular file
                $handle = fopen($filepath, 'r');
                if (!$handle) {
                    return;
                }

                $lineNumber = 0;
                while (($line = fgets($handle)) !== false && $count < $maxResults) {
                    $lineNumber++;

                    if (stripos($line, $pattern) !== false) {
                        $results[] = [
                            'file' => $relativePath,
                            'path' => $filepath,
                            'line_number' => $lineNumber,
                            'line' => trim($line),
                            'is_gzip' => false
                        ];
                        $count++;
                    }
                }

                fclose($handle);
            }
        } catch (\Exception $e) {
            // Continue on error
        }
    }
}
