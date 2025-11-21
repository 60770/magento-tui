<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Service for collecting system and application statistics
 */
class StatisticsService
{
    /**
     * @param ProductMetadataInterface $productMetadata
     * @param ResourceConnection $resourceConnection
     * @param ModuleListInterface $moduleList
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleListInterface $moduleList,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Get system information
     *
     * @return array
     */
    public function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'magento_version' => $this->productMetadata->getVersion(),
            'magento_edition' => $this->productMetadata->getEdition(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'operating_system' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'current_memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory_usage' => $this->formatBytes(memory_get_peak_usage(true))
        ];
    }

    /**
     * Get database statistics
     *
     * @return array
     */
    public function getDatabaseStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $dbConfig = $this->deploymentConfig->get('db/connection/default');
        
        // Get database size
        $dbName = $dbConfig['dbname'] ?? '';
        $query = "SELECT 
            COUNT(*) as table_count,
            SUM(data_length + index_length) as total_size,
            SUM(data_length) as data_size,
            SUM(index_length) as index_size
            FROM information_schema.TABLES 
            WHERE table_schema = ?";
        
        $stats = $connection->fetchRow($query, [$dbName]);

        return [
            'database_name' => $dbName,
            'host' => $dbConfig['host'] ?? 'localhost',
            'table_count' => (int)($stats['table_count'] ?? 0),
            'total_size' => $this->formatBytes((int)($stats['total_size'] ?? 0)),
            'data_size' => $this->formatBytes((int)($stats['data_size'] ?? 0)),
            'index_size' => $this->formatBytes((int)($stats['index_size'] ?? 0))
        ];
    }

    /**
     * Get module statistics
     *
     * @return array
     */
    public function getModuleStats(): array
    {
        $allModules = $this->moduleList->getAll();
        $enabledModules = array_filter($allModules, fn($module) => isset($module['setup_version']));

        $vendorCounts = [];
        foreach ($allModules as $moduleName => $moduleData) {
            $vendor = explode('_', $moduleName)[0];
            $vendorCounts[$vendor] = ($vendorCounts[$vendor] ?? 0) + 1;
        }

        arsort($vendorCounts);

        return [
            'total_modules' => count($allModules),
            'enabled_modules' => count($enabledModules),
            'vendor_distribution' => array_slice($vendorCounts, 0, 10),
            'custom_modules' => $this->getCustomModules($allModules)
        ];
    }

    /**
     * Get custom (non-Magento) modules
     *
     * @param array $allModules
     * @return array
     */
    private function getCustomModules(array $allModules): array
    {
        $customModules = [];
        
        foreach ($allModules as $moduleName => $moduleData) {
            if (!str_starts_with($moduleName, 'Magento_')) {
                $customModules[] = [
                    'name' => $moduleName,
                    'version' => $moduleData['setup_version'] ?? 'N/A'
                ];
            }
        }

        return $customModules;
    }

    /**
     * Get performance metrics
     *
     * @return array
     */
    public function getPerformanceMetrics(): array
    {
        $connection = $this->resourceConnection->getConnection();
        
        // Get some basic performance indicators
        $uptime = (int)$connection->fetchOne("SHOW STATUS LIKE 'Uptime'");
        $queries = (int)$connection->fetchOne("SHOW STATUS LIKE 'Queries'");
        
        return [
            'server_uptime' => $this->formatUptime($uptime),
            'total_queries' => $queries,
            'avg_queries_per_second' => $uptime > 0 ? round($queries / $uptime, 2) : 0,
            'php_extensions' => count(get_loaded_extensions()),
            'loaded_classes' => count(get_declared_classes())
        ];
    }

    /**
     * Get all statistics
     *
     * @return array
     */
    public function getAllStatistics(): array
    {
        return [
            'system' => $this->getSystemInfo(),
            'database' => $this->getDatabaseStats(),
            'modules' => $this->getModuleStats(),
            'performance' => $this->getPerformanceMetrics()
        ];
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Format uptime to human readable format
     *
     * @param int $seconds
     * @return string
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        
        return implode(' ', $parts) ?: '0m';
    }
}
