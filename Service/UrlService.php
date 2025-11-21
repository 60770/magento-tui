<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Service for managing store URLs
 */
class UrlService
{
    private const BASE_URL_PATH = 'web/unsecure/base_url';
    private const BASE_URL_SECURE_PATH = 'web/secure/base_url';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Get all store URLs
     *
     * @return array
     */
    public function getAllStoreUrls(): array
    {
        $urls = [];

        try {
            $stores = $this->storeManager->getStores(true); // Include admin

            foreach ($stores as $store) {
                $storeId = $store->getId();
                $storeName = $store->getName();
                $storeCode = $store->getCode();

                $baseUrl = $this->scopeConfig->getValue(
                    self::BASE_URL_PATH,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );

                $baseUrlSecure = $this->scopeConfig->getValue(
                    self::BASE_URL_SECURE_PATH,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );

                $urls[] = [
                    'store_id' => $storeId,
                    'store_name' => $storeName,
                    'store_code' => $storeCode,
                    'base_url' => $baseUrl ?: 'Not set',
                    'base_url_secure' => $baseUrlSecure ?: 'Not set',
                ];
            }
        } catch (\Exception $e) {
            // Return empty if error
        }

        return $urls;
    }

    /**
     * Update store URLs
     *
     * @param int $storeId
     * @param string $baseUrl
     * @param string $baseUrlSecure
     * @return array
     */
    public function updateStoreUrls(int $storeId, string $baseUrl, string $baseUrlSecure): array
    {
        try {
            // Validate URLs
            if (!$this->validateUrl($baseUrl)) {
                return [
                    'success' => false,
                    'message' => 'Invalid base URL format'
                ];
            }

            if (!$this->validateUrl($baseUrlSecure)) {
                return [
                    'success' => false,
                    'message' => 'Invalid secure base URL format'
                ];
            }

            // Ensure URLs end with /
            $baseUrl = rtrim($baseUrl, '/') . '/';
            $baseUrlSecure = rtrim($baseUrlSecure, '/') . '/';

            // Save configuration
            $this->configWriter->save(
                self::BASE_URL_PATH,
                $baseUrl,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORES,
                $storeId
            );

            $this->configWriter->save(
                self::BASE_URL_SECURE_PATH,
                $baseUrlSecure,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORES,
                $storeId
            );

            // Clear config cache
            $this->cacheTypeList->cleanType('config');

            return [
                'success' => true,
                'message' => 'URLs updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate URL format
     *
     * @param string $url
     * @return bool
     */
    private function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
