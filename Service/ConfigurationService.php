<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Service for managing Magento configuration
 */
class ConfigurationService
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param CollectionFactory $configCollectionFactory
     * @param TypeListInterface $cacheTypeList
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly CollectionFactory $configCollectionFactory,
        private readonly TypeListInterface $cacheTypeList,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Get all configuration values with source and final value
     *
     * @return array
     */
    public function getAllConfigurations(): array
    {
        $collection = $this->configCollectionFactory->create();
        $configs = [];

        // Load env.php and config.php
        $envConfig = $this->loadEnvConfig();
        $appConfig = $this->loadAppConfig();

        foreach ($collection as $config) {
            $path = $config->getPath();
            $dbValue = $config->getValue();

            // Determine source and get final value
            $source = $this->determineConfigSource($path, $envConfig, $appConfig);
            $finalValue = $this->scopeConfig->getValue($path, $config->getScope(), $config->getScopeId());

            $configs[] = [
                'path' => $path,
                'value' => $dbValue,
                'scope' => $config->getScope(),
                'scope_id' => $config->getScopeId(),
                'source' => $source,
                'final_value' => $finalValue,
                'is_overridden' => ($dbValue != $finalValue && $source !== 'database')
            ];
        }

        return $configs;
    }

    /**
     * Load env.php configuration
     *
     * @return array
     */
    private function loadEnvConfig(): array
    {
        try {
            $envPath = BP . '/app/etc/env.php';
            if (file_exists($envPath)) {
                return include $envPath;
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return [];
    }

    /**
     * Load config.php configuration
     *
     * @return array
     */
    private function loadAppConfig(): array
    {
        try {
            $configPath = BP . '/app/etc/config.php';
            if (file_exists($configPath)) {
                return include $configPath;
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return [];
    }

    /**
     * Determine configuration source
     *
     * @param string $path
     * @param array $envConfig
     * @param array $appConfig
     * @return string
     */
    private function determineConfigSource(string $path, array $envConfig, array $appConfig): string
    {
        // Check env.php first (highest priority)
        $envValue = $this->getNestedValue($envConfig, $this->pathToArray($path));
        if ($envValue !== null) {
            return 'env.php';
        }

        // Check config.php
        $appValue = $this->getNestedValue($appConfig, $this->pathToArray($path));
        if ($appValue !== null) {
            return 'config.php';
        }

        // Default to database
        return 'database';
    }

    /**
     * Convert config path to array
     *
     * @param string $path
     * @return array
     */
    private function pathToArray(string $path): array
    {
        return explode('/', $path);
    }

    /**
     * Get nested value from array
     *
     * @param array $array
     * @param array $keys
     * @return mixed|null
     */
    private function getNestedValue(array $array, array $keys)
    {
        $current = $array;
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Get configuration value by path
     *
     * @param string $path
     * @param string $scope
     * @param int|null $scopeId
     * @return mixed
     */
    public function getConfigValue(string $path, string $scope = 'default', ?int $scopeId = 0)
    {
        return $this->scopeConfig->getValue($path, $scope, $scopeId);
    }

    /**
     * Save configuration value
     *
     * @param string $path
     * @param mixed $value
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    public function saveConfigValue(string $path, $value, string $scope = 'default', int $scopeId = 0): void
    {
        $this->configWriter->save($path, $value, $scope, $scopeId);
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Search configurations by path pattern
     *
     * @param string $pattern
     * @return array
     */
    public function searchConfigurations(string $pattern): array
    {
        $allConfigs = $this->getAllConfigurations();
        $pattern = strtolower($pattern);

        return array_filter($allConfigs, function ($config) use ($pattern) {
            return str_contains(strtolower($config['path']), $pattern);
        });
    }

    /**
     * Validate configuration path
     *
     * @param string $path
     * @return bool
     */
    public function validatePath(string $path): bool
    {
        return !empty($path) && preg_match('/^[a-z0-9_\/]+$/i', $path);
    }
}
