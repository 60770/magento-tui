<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Module\Status;
use Magento\Framework\App\State;
use Magento\Framework\App\DeploymentConfig;
use Symfony\Component\Process\Process;

/**
 * Service for managing Magento modules
 */
class ModuleService
{
    /**
     * @param ModuleListInterface $moduleList
     * @param ModuleManager $moduleManager
     * @param Status $moduleStatus
     * @param State $appState
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ModuleManager $moduleManager,
        private readonly Status $moduleStatus,
        private readonly State $appState,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Get all modules with their status (reads directly from config.php)
     *
     * @return array
     */
    public function getAllModules(): array
    {
        $modules = [];

        // Read directly from config.php to avoid caching issues
        $configPath = BP . '/app/etc/config.php';

        if (!file_exists($configPath)) {
            return [];
        }

        try {
            $config = include $configPath;
            $moduleConfig = $config['modules'] ?? [];

            if (!is_array($moduleConfig)) {
                return [];
            }

            // Get module data from enabled modules list
            $enabledModulesData = $this->moduleList->getAll();

            foreach ($moduleConfig as $moduleName => $isEnabled) {
                $modules[] = [
                    'name' => $moduleName,
                    'setup_version' => $enabledModulesData[$moduleName]['setup_version'] ?? 'N/A',
                    'enabled' => (bool)$isEnabled,
                ];
            }

            // Sort by name
            usort($modules, fn($a, $b) => strcmp($a['name'], $b['name']));

            return $modules;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Enable a module using bin/magento command
     *
     * @param string $moduleName
     * @return array Result with success status, message, and full output
     */
    public function enableModule(string $moduleName): array
    {
        try {
            $command = sprintf('bin/magento module:enable %s', escapeshellarg($moduleName));
            $process = Process::fromShellCommandline($command, BP, null, null, 60);
            $process->run();

            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());
            $fullOutput = $errorOutput ?: $output;

            // Clear opcache to ensure config.php is reloaded
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            if (!$process->isSuccessful()) {
                // Parse dependency errors
                $message = $this->parseModuleError($fullOutput, $moduleName, 'enable');

                return [
                    'success' => false,
                    'message' => $message,
                    'full_output' => $fullOutput,
                    'has_details' => true
                ];
            }

            // Verify the change was written
            $configPath = BP . '/app/etc/config.php';
            $config = include $configPath;
            $actualStatus = $config['modules'][$moduleName] ?? null;

            if ($actualStatus !== 1) {
                return [
                    'success' => false,
                    'message' => "Module '$moduleName' enable failed - config.php not updated",
                    'full_output' => $fullOutput,
                    'has_details' => false
                ];
            }

            return [
                'success' => true,
                'message' => "Module '$moduleName' enabled successfully",
                'full_output' => $fullOutput,
                'has_details' => false
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'full_output' => $e->getMessage(),
                'has_details' => false
            ];
        }
    }

    /**
     * Disable a module using bin/magento command
     *
     * @param string $moduleName
     * @return array Result with success status, message, and full output
     */
    public function disableModule(string $moduleName): array
    {
        try {
            $command = sprintf('bin/magento module:disable %s', escapeshellarg($moduleName));
            $process = Process::fromShellCommandline($command, BP, null, null, 60);
            $process->run();

            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());
            $fullOutput = $errorOutput ?: $output;

            // Clear opcache to ensure config.php is reloaded
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            if (!$process->isSuccessful()) {
                // Parse dependency errors
                $message = $this->parseModuleError($fullOutput, $moduleName, 'disable');

                return [
                    'success' => false,
                    'message' => $message,
                    'full_output' => $fullOutput,
                    'has_details' => true
                ];
            }

            // Verify the change was written
            $configPath = BP . '/app/etc/config.php';
            $config = include $configPath;
            $actualStatus = $config['modules'][$moduleName] ?? null;

            if ($actualStatus !== 0) {
                return [
                    'success' => false,
                    'message' => "Module '$moduleName' disable failed - config.php not updated (status: $actualStatus)",
                    'full_output' => $fullOutput,
                    'has_details' => false
                ];
            }

            return [
                'success' => true,
                'message' => "Module '$moduleName' disabled successfully",
                'full_output' => $fullOutput,
                'has_details' => false
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'full_output' => $e->getMessage(),
                'has_details' => false
            ];
        }
    }

    /**
     * Parse module command error output to extract meaningful message
     *
     * @param string $output
     * @param string $moduleName
     * @param string $action 'enable' or 'disable'
     * @return string
     */
    private function parseModuleError(string $output, string $moduleName, string $action): string
    {
        // Check for dependency errors
        if (stripos($output, 'depend') !== false || stripos($output, 'require') !== false) {
            // Extract module dependencies
            if (preg_match_all('/([A-Za-z0-9_]+)/', $output, $matches)) {
                $dependencies = array_filter($matches[1], function($mod) use ($moduleName) {
                    return $mod !== $moduleName && strpos($mod, '_') !== false;
                });

                if (!empty($dependencies)) {
                    $depList = implode(', ', array_unique($dependencies));
                    if ($action === 'enable') {
                        return "Cannot enable '$moduleName': missing dependencies: $depList";
                    } else {
                        return "Cannot disable '$moduleName': required by: $depList";
                    }
                }
            }
            return "Dependency error - check module dependencies";
        }

        // Check for "module already enabled/disabled"
        if (stripos($output, 'already enabled') !== false) {
            return "Module '$moduleName' is already enabled";
        }
        if (stripos($output, 'already disabled') !== false) {
            return "Module '$moduleName' is already disabled";
        }

        // Check for missing module
        if (stripos($output, 'not found') !== false || stripos($output, 'does not exist') !== false) {
            return "Module '$moduleName' not found";
        }

        // Generic error - return first meaningful line
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && !preg_match('/^[\[\]]+$/', $line)) {
                return substr($line, 0, 100); // First 100 chars
            }
        }

        return "Error {$action}ing module - see details";
    }

    /**
     * Get module statistics
     *
     * @return array
     */
    public function getModuleStatistics(): array
    {
        $modules = $this->getAllModules();

        $stats = [
            'total' => count($modules),
            'enabled' => 0,
            'disabled' => 0,
        ];

        foreach ($modules as $module) {
            if ($module['enabled']) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
        }

        return $stats;
    }
}
