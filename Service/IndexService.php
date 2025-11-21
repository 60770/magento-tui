<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Symfony\Component\Process\Process;

/**
 * Service for managing Magento indexers
 */
class IndexService
{
    private ?Process $currentProcess = null;
    private string $processOutput = '';
    private bool $isProcessing = false;

    /**
     * @param IndexerRegistry $indexerRegistry
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * Get all indexers with their status (fresh data)
     *
     * @return array
     */
    public function getAllIndexers(): array
    {
        $indexers = [];

        // Common indexer IDs
        $indexerIds = [
            'catalog_category_product',
            'catalog_product_category',
            'catalog_product_attribute',
            'catalog_product_price',
            'cataloginventory_stock',
            'inventory',
            'catalogrule_rule',
            'catalogrule_product',
            'catalogsearch_fulltext',
            'customer_grid',
            'design_config_grid',
        ];

        foreach ($indexerIds as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);

                // Force reload state from database
                $state = $indexer->getState();
                $state->loadByIndexer($indexerId);

                // Get view configuration
                $view = $indexer->getView();
                $isScheduled = $indexer->isScheduled();

                $indexers[] = [
                    'id' => $indexer->getId(),
                    'title' => $indexer->getTitle(),
                    'status' => $state->getStatus(),
                    'updated' => $state->getUpdated(),
                    'mode' => $isScheduled ? 'Update by Schedule' : 'Update on Save',
                    'is_scheduled' => $isScheduled,
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        return $indexers;
    }

    /**
     * Get indexer status text
     *
     * @param string $status
     * @return string
     */
    public function getStatusText(string $status): string
    {
        return match ($status) {
            StateInterface::STATUS_VALID => 'Ready',
            StateInterface::STATUS_INVALID => 'Reindex Required',
            StateInterface::STATUS_WORKING => 'Processing',
            default => 'Unknown'
        };
    }

    /**
     * Reindex a specific indexer (background process)
     *
     * @param string $indexerId
     * @return void
     */
    public function reindex(string $indexerId): void
    {
        $command = sprintf('bin/magento indexer:reindex %s', escapeshellarg($indexerId));
        $this->executeInBackground($command);
    }

    /**
     * Reindex all indexers (background process)
     *
     * @return void
     */
    public function reindexAll(): void
    {
        $command = 'bin/magento indexer:reindex';
        $this->executeInBackground($command);
    }

    /**
     * Execute command in background
     *
     * @param string $command
     * @return void
     */
    private function executeInBackground(string $command): void
    {
        if ($this->isProcessing) {
            return; // Already processing
        }

        $this->processOutput = '';
        $this->isProcessing = true;

        $this->currentProcess = Process::fromShellCommandline($command, BP, null, null, 600);
        $this->currentProcess->start();
    }

    /**
     * Check if reindexing is in progress
     *
     * @return bool
     */
    public function isReindexing(): bool
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
     * Get reindexing progress output
     *
     * @return string
     */
    public function getReindexOutput(): string
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
     * Clear process output
     *
     * @return void
     */
    public function clearOutput(): void
    {
        $this->processOutput = '';
        $this->currentProcess = null;
        $this->isProcessing = false;
    }

    /**
     * Reset indexer (invalidate)
     *
     * @param string $indexerId
     * @return void
     */
    public function resetIndex(string $indexerId): void
    {
        $indexer = $this->indexerRegistry->get($indexerId);
        $state = $indexer->getState();
        $state->setStatus(StateInterface::STATUS_INVALID);
        $state->save();

        // Reload state to reflect changes
        $state->loadByIndexer($indexerId);
    }

    /**
     * Set indexer mode
     *
     * @param string $indexerId
     * @param bool $scheduled
     * @return void
     */
    public function setMode(string $indexerId, bool $scheduled): void
    {
        $indexer = $this->indexerRegistry->get($indexerId);
        $indexer->setScheduled($scheduled);

        // Clear config cache to reflect changes
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Get indexer statistics
     *
     * @return array
     */
    public function getIndexerStatistics(): array
    {
        $indexers = $this->getAllIndexers();
        $stats = [
            'total' => count($indexers),
            'valid' => 0,
            'invalid' => 0,
            'working' => 0,
            'scheduled' => 0,
            'realtime' => 0,
        ];

        foreach ($indexers as $indexer) {
            switch ($indexer['status']) {
                case StateInterface::STATUS_VALID:
                    $stats['valid']++;
                    break;
                case StateInterface::STATUS_INVALID:
                    $stats['invalid']++;
                    break;
                case StateInterface::STATUS_WORKING:
                    $stats['working']++;
                    break;
            }

            if ($indexer['is_scheduled']) {
                $stats['scheduled']++;
            } else {
                $stats['realtime']++;
            }
        }

        return $stats;
    }
}
