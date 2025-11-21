<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem\DirectoryList;

/**
 * Service for managing Magento maintenance mode
 */
class MaintenanceService
{
    /**
     * @param MaintenanceMode $maintenanceMode
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * Check if maintenance mode is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->maintenanceMode->isOn();
    }

    /**
     * Enable maintenance mode
     *
     * @return bool
     */
    public function enable(): bool
    {
        try {
            $this->maintenanceMode->set(true);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Disable maintenance mode
     *
     * @return bool
     */
    public function disable(): bool
    {
        try {
            $this->maintenanceMode->set(false);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of allowed IP addresses
     *
     * @return array
     */
    public function getAllowedIPs(): array
    {
        return $this->maintenanceMode->getAddressInfo();
    }

    /**
     * Add IP address to allowed list
     *
     * @param string $ip
     * @return bool
     */
    public function addAllowedIP(string $ip): bool
    {
        try {
            // Validate IP format
            if (!$this->validateIP($ip)) {
                return false;
            }

            $currentIPs = $this->getAllowedIPs();

            // Check if IP already exists
            if (in_array($ip, $currentIPs)) {
                return false;
            }

            $currentIPs[] = $ip;
            $this->maintenanceMode->setAddresses(implode(',', $currentIPs));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove IP address from allowed list
     *
     * @param string $ip
     * @return bool
     */
    public function removeAllowedIP(string $ip): bool
    {
        try {
            $currentIPs = $this->getAllowedIPs();

            $key = array_search($ip, $currentIPs);
            if ($key === false) {
                return false;
            }

            unset($currentIPs[$key]);
            $this->maintenanceMode->setAddresses(implode(',', $currentIPs));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear all allowed IPs
     *
     * @return bool
     */
    public function clearAllowedIPs(): bool
    {
        try {
            $this->maintenanceMode->setAddresses('');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current client IP
     *
     * @return string
     */
    public function getCurrentIP(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return 'Unknown';
    }

    /**
     * Validate IP address format
     *
     * @param string $ip
     * @return bool
     */
    private function validateIP(string $ip): bool
    {
        // Check for valid IPv4 or IPv6
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Get maintenance mode status information
     *
     * @return array
     */
    public function getStatus(): array
    {
        $flagFile = $this->directoryList->getPath('var') . '/.maintenance.flag';
        $ipFile = $this->directoryList->getPath('var') . '/.maintenance.ip';

        return [
            'enabled' => $this->isEnabled(),
            'allowed_ips' => $this->getAllowedIPs(),
            'allowed_count' => count($this->getAllowedIPs()),
            'current_ip' => $this->getCurrentIP(),
            'flag_file_exists' => file_exists($flagFile),
            'ip_file_exists' => file_exists($ipFile),
            'flag_file_path' => $flagFile,
            'ip_file_path' => $ipFile
        ];
    }

    /**
     * Toggle maintenance mode
     *
     * @return bool New state (true = enabled, false = disabled)
     */
    public function toggle(): bool
    {
        if ($this->isEnabled()) {
            $this->disable();
            return false;
        } else {
            $this->enable();
            return true;
        }
    }
}
