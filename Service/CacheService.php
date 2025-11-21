<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\StateInterface;

/**
 * Service for managing Magento cache
 */
class CacheService
{
    /**
     * @param TypeListInterface $cacheTypeList
     * @param Pool $cacheFrontendPool
     * @param StateInterface $cacheState
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly Pool $cacheFrontendPool,
        private readonly StateInterface $cacheState
    ) {
    }

    /**
     * Get all cache types with their status
     *
     * @return array
     */
    public function getAllCacheTypes(): array
    {
        $cacheTypes = [];

        foreach ($this->cacheTypeList->getTypes() as $type => $data) {
            $isEnabled = $this->cacheState->isEnabled($type);
            $cacheTypes[] = [
                'id' => $type,
                'label' => $data->getCacheType(),
                'description' => $data->getDescription(),
                'status' => $isEnabled ? 1 : 0,
                'enabled' => $isEnabled
            ];
        }

        return $cacheTypes;
    }

    /**
     * Get cache type status
     *
     * @param string $cacheType
     * @return bool
     */
    public function getCacheStatus(string $cacheType): bool
    {
        return $this->cacheState->isEnabled($cacheType);
    }

    /**
     * Enable cache type
     *
     * @param string $cacheType
     * @return void
     */
    public function enableCache(string $cacheType): void
    {
        $this->cacheState->setEnabled($cacheType, true);
        $this->cacheState->persist();
        $this->cacheTypeList->cleanType($cacheType);
    }

    /**
     * Disable cache type
     *
     * @param string $cacheType
     * @return void
     */
    public function disableCache(string $cacheType): void
    {
        $this->cacheState->setEnabled($cacheType, false);
        $this->cacheState->persist();
        $this->cacheTypeList->cleanType($cacheType);
    }

    /**
     * Flush cache type
     *
     * @param string $cacheType
     * @return void
     */
    public function flushCache(string $cacheType): void
    {
        $this->cacheTypeList->cleanType($cacheType);
    }

    /**
     * Flush all cache types
     *
     * @return void
     */
    public function flushAllCache(): void
    {
        $types = array_keys($this->cacheTypeList->getTypes());
        
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getCacheStatistics(): array
    {
        $types = $this->getAllCacheTypes();
        $enabled = count(array_filter($types, fn($type) => $type['enabled']));
        $disabled = count($types) - $enabled;

        return [
            'total' => count($types),
            'enabled' => $enabled,
            'disabled' => $disabled,
            'types' => $types
        ];
    }

    /**
     * Toggle cache type status
     *
     * @param string $cacheType
     * @return bool New status
     */
    public function toggleCache(string $cacheType): bool
    {
        $currentStatus = $this->getCacheStatus($cacheType);
        
        if ($currentStatus) {
            $this->disableCache($cacheType);
            return false;
        } else {
            $this->enableCache($cacheType);
            return true;
        }
    }

    /**
     * Enable all cache types
     *
     * @return void
     */
    public function enableAllCaches(): void
    {
        $types = array_keys($this->cacheTypeList->getTypes());
        foreach ($types as $type) {
            $this->enableCache($type);
        }
    }

    /**
     * Disable all cache types
     *
     * @return void
     */
    public function disableAllCaches(): void
    {
        $types = array_keys($this->cacheTypeList->getTypes());
        foreach ($types as $type) {
            $this->disableCache($type);
        }
    }
}
