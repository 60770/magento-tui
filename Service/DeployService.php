<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Symfony\Component\Process\Process;

/**
 * Service for managing deployment operations
 */
class DeployService
{
    /**
     * @param DeploymentConfig $deploymentConfig
     * @param State $appState
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly State $appState
    ) {
    }

    /**
     * Get deployment status
     *
     * @return array
     */
    public function getDeploymentStatus(): array
    {
        try {
            $mode = $this->getCurrentMode();
        } catch (\Exception $e) {
            $mode = 'unknown';
        }

        try {
            $staticDeployed = $this->isStaticContentDeployed();
        } catch (\Exception $e) {
            $staticDeployed = false;
        }

        try {
            $diCompiled = $this->isDiCompiled();
        } catch (\Exception $e) {
            $diCompiled = false;
        }

        return [
            'mode' => $mode,
            'static_content_deployed' => $staticDeployed,
            'di_compiled' => $diCompiled,
            'maintenance_enabled' => file_exists(BP . '/var/.maintenance.flag'),
        ];
    }

    /**
     * Check if static content is deployed
     *
     * @return bool
     */
    private function isStaticContentDeployed(): bool
    {
        try {
            $staticDir = BP . '/pub/static';
            if (!is_dir($staticDir)) {
                return false;
            }

            // Check if adminhtml static content exists
            $adminhtmlDir = $staticDir . '/adminhtml';
            if (!is_dir($adminhtmlDir)) {
                return false;
            }

            $files = @scandir($adminhtmlDir);
            return $files !== false && count($files) > 2;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if DI is compiled
     *
     * @return bool
     */
    private function isDiCompiled(): bool
    {
        try {
            $generatedDir = BP . '/generated/code';
            if (!is_dir($generatedDir)) {
                return false;
            }

            $files = @scandir($generatedDir);
            return $files !== false && count($files) > 2;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get available deployment operations
     *
     * @return array
     */
    public function getAvailableOperations(): array
    {
        return [
            [
                'id' => 'setup:upgrade',
                'title' => 'Run setup:upgrade',
                'description' => 'Update database schema and data',
                'command' => 'bin/magento setup:upgrade',
            ],
            [
                'id' => 'setup:di:compile',
                'title' => 'Compile DI',
                'description' => 'Generate DI configuration',
                'command' => 'bin/magento setup:di:compile',
            ],
            [
                'id' => 'setup:static-content:deploy',
                'title' => 'Deploy static content',
                'description' => 'Deploy static view files',
                'command' => 'bin/magento setup:static-content:deploy -f',
            ],
            [
                'id' => 'cache:flush',
                'title' => 'Flush cache',
                'description' => 'Flush all cache storage',
                'command' => 'bin/magento cache:flush',
            ],
            [
                'id' => 'indexer:reindex',
                'title' => 'Reindex all',
                'description' => 'Reindex all indexers',
                'command' => 'bin/magento indexer:reindex',
            ],
        ];
    }

    private ?Process $currentProcess = null;
    private string $processOutput = '';
    private bool $isExecuting = false;

    /**
     * Execute deployment command
     *
     * @param string $command
     * @return void
     */
    public function executeCommand(string $command): void
    {
        if ($this->isExecuting) {
            return;
        }

        try {
            $this->processOutput = '';
            $this->isExecuting = true;
            
            $this->currentProcess = Process::fromShellCommandline($command, BP, null, null, 300);
            $this->currentProcess->start();
        } catch (\Exception $e) {
            $this->processOutput = 'Failed to start command: ' . $e->getMessage();
            $this->isExecuting = false;
        }
    }

    /**
     * Check if command is executing
     *
     * @return bool
     */
    public function isExecuting(): bool
    {
        if ($this->currentProcess && $this->currentProcess->isRunning()) {
            return true;
        }

        if ($this->currentProcess && !$this->currentProcess->isRunning() && $this->isExecuting) {
            // Process just finished
            $this->processOutput .= $this->currentProcess->getOutput();
            $this->processOutput .= $this->currentProcess->getErrorOutput();
            $this->isExecuting = false;
        }

        return $this->isExecuting;
    }

    /**
     * Get command output
     *
     * @return string
     */
    public function getOutput(): string
    {
        if ($this->currentProcess && $this->currentProcess->isRunning()) {
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
     * Clear output and reset state
     *
     * @return void
     */
    public function clearOutput(): void
    {
        $this->processOutput = '';
        $this->currentProcess = null;
        $this->isExecuting = false;
    }

    /**
     * Get current Magento mode
     *
     * @return string
     */
    public function getCurrentMode(): string
    {
        try {
            // Try to get from app state
            $mode = $this->appState->getMode();
            return $mode;
        } catch (\Exception $e) {
            // Fallback: read from env.php
            try {
                $envConfig = $this->deploymentConfig->get('MAGE_MODE');
                if ($envConfig) {
                    return $envConfig;
                }
            } catch (\Exception $e2) {
                // Ignore
            }
            return 'default';
        }
    }

    /**
     * Check if in developer mode
     *
     * @return bool
     */
    public function isDeveloperMode(): bool
    {
        return $this->getCurrentMode() === State::MODE_DEVELOPER;
    }

    /**
     * Check if in production mode
     *
     * @return bool
     */
    public function isProductionMode(): bool
    {
        return $this->getCurrentMode() === State::MODE_PRODUCTION;
    }
}
