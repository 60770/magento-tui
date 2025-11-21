<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

/**
 * Service for collecting order and sales statistics
 */
class OrderStatsService
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CustomerCollectionFactory $customerCollectionFactory
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CustomerCollectionFactory $customerCollectionFactory
    ) {
    }

    /**
     * Get order statistics for today
     *
     * @return array
     */
    public function getTodayStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $query = "SELECT
            COUNT(*) as order_count,
            COALESCE(SUM(grand_total), 0) as total_revenue,
            COALESCE(AVG(grand_total), 0) as avg_order_value
            FROM {$orderTable}
            WHERE created_at BETWEEN ? AND ?
            AND status != 'canceled'";

        $stats = $connection->fetchRow($query, [$todayStart, $todayEnd]);

        return [
            'order_count' => (int)($stats['order_count'] ?? 0),
            'total_revenue' => number_format((float)($stats['total_revenue'] ?? 0), 2),
            'avg_order_value' => number_format((float)($stats['avg_order_value'] ?? 0), 2)
        ];
    }

    /**
     * Get order statistics for last 7 days
     *
     * @return array
     */
    public function getWeekStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $weekStart = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $weekEnd = date('Y-m-d 23:59:59');

        $query = "SELECT
            COUNT(*) as order_count,
            COALESCE(SUM(grand_total), 0) as total_revenue
            FROM {$orderTable}
            WHERE created_at BETWEEN ? AND ?
            AND status != 'canceled'";

        $stats = $connection->fetchRow($query, [$weekStart, $weekEnd]);

        return [
            'order_count' => (int)($stats['order_count'] ?? 0),
            'total_revenue' => number_format((float)($stats['total_revenue'] ?? 0), 2)
        ];
    }

    /**
     * Get order statistics for current month
     *
     * @return array
     */
    public function getMonthStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd = date('Y-m-t 23:59:59');

        $query = "SELECT
            COUNT(*) as order_count,
            COALESCE(SUM(grand_total), 0) as total_revenue
            FROM {$orderTable}
            WHERE created_at BETWEEN ? AND ?
            AND status != 'canceled'";

        $stats = $connection->fetchRow($query, [$monthStart, $monthEnd]);

        return [
            'order_count' => (int)($stats['order_count'] ?? 0),
            'total_revenue' => number_format((float)($stats['total_revenue'] ?? 0), 2)
        ];
    }

    /**
     * Get order status distribution
     *
     * @return array
     */
    public function getOrderStatusDistribution(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $query = "SELECT
            status,
            COUNT(*) as count
            FROM {$orderTable}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY status
            ORDER BY count DESC
            LIMIT 10";

        $results = $connection->fetchAll($query);

        $distribution = [];
        foreach ($results as $row) {
            $distribution[$row['status']] = (int)$row['count'];
        }

        return $distribution;
    }

    /**
     * Get top products by quantity sold
     *
     * @param int $limit
     * @return array
     */
    public function getTopProducts(int $limit = 5): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        $query = "SELECT
            name,
            SUM(qty_ordered) as total_qty,
            SUM(row_total) as total_revenue
            FROM {$orderItemTable}
            WHERE parent_item_id IS NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY sku, name
            ORDER BY total_qty DESC
            LIMIT ?";

        return $connection->fetchAll($query, [$limit]);
    }

    /**
     * Get catalog statistics
     *
     * @return array
     */
    public function getCatalogStats(): array
    {
        $productCollection = $this->productCollectionFactory->create();

        $totalProducts = $productCollection->getSize();

        $enabledProducts = $this->productCollectionFactory->create()
            ->addAttributeToFilter('status', 1)
            ->getSize();

        return [
            'total_products' => $totalProducts,
            'enabled_products' => $enabledProducts,
            'disabled_products' => $totalProducts - $enabledProducts
        ];
    }

    /**
     * Get customer statistics
     *
     * @return array
     */
    public function getCustomerStats(): array
    {
        $customerCollection = $this->customerCollectionFactory->create();

        $totalCustomers = $customerCollection->getSize();

        // Get new customers this month
        $monthStart = date('Y-m-01 00:00:00');
        $newCustomers = $this->customerCollectionFactory->create()
            ->addAttributeToFilter('created_at', ['gteq' => $monthStart])
            ->getSize();

        return [
            'total_customers' => $totalCustomers,
            'new_this_month' => $newCustomers
        ];
    }

    /**
     * Get recent orders
     *
     * @param int $limit
     * @return array
     */
    public function getRecentOrders(int $limit = 10): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $query = "SELECT
            increment_id,
            status,
            grand_total,
            created_at
            FROM {$orderTable}
            ORDER BY created_at DESC
            LIMIT ?";

        return $connection->fetchAll($query, [$limit]);
    }

    /**
     * Get daily order counts for last N days
     *
     * @param int $days
     * @return array
     */
    public function getDailyOrderCounts(int $days = 7): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $orders = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayStart = $date . ' 00:00:00';
            $dayEnd = $date . ' 23:59:59';

            $query = "SELECT COUNT(*) as count
                FROM {$orderTable}
                WHERE created_at BETWEEN ? AND ?
                AND status != 'canceled'";

            $count = $connection->fetchOne($query, [$dayStart, $dayEnd]);
            $orders[$date] = (int)$count;
        }

        return $orders;
    }

    /**
     * Get online customers count (last 5 minutes activity)
     *
     * @return int
     */
    public function getOnlineCustomers(): int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $visitorTable = $this->resourceConnection->getTableName('customer_visitor');

            $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));

            $query = "SELECT COUNT(DISTINCT visitor_id) as count
                FROM {$visitorTable}
                WHERE last_visit_at >= ?";

            return (int)$connection->fetchOne($query, [$fiveMinutesAgo]);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get total orders count (all time)
     *
     * @return int
     */
    public function getTotalOrdersCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        return (int)$connection->fetchOne("SELECT COUNT(*) FROM {$orderTable}");
    }

    /**
     * Get RMA/Returns statistics
     *
     * @return array
     */
    public function getRMAStats(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $rmaTable = $this->resourceConnection->getTableName('magento_rma');

            // Check if RMA table exists (Enterprise only)
            $tables = $connection->fetchCol("SHOW TABLES LIKE '{$rmaTable}'");

            if (empty($tables)) {
                return [
                    'total' => 0,
                    'pending' => 0,
                    'this_month' => 0
                ];
            }

            $monthStart = date('Y-m-01 00:00:00');

            $total = (int)$connection->fetchOne("SELECT COUNT(*) FROM {$rmaTable}");
            $pending = (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM {$rmaTable} WHERE status IN ('pending', 'authorized')"
            );
            $thisMonth = (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM {$rmaTable} WHERE date_requested >= ?",
                [$monthStart]
            );

            return [
                'total' => $total,
                'pending' => $pending,
                'this_month' => $thisMonth
            ];
        } catch (\Exception $e) {
            return [
                'total' => 0,
                'pending' => 0,
                'this_month' => 0
            ];
        }
    }

    /**
     * Get revenue statistics
     *
     * @return array
     */
    public function getRevenueStats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $query = "SELECT
            COALESCE(SUM(grand_total), 0) as total_revenue,
            COALESCE(AVG(grand_total), 0) as avg_order_value,
            COALESCE(MAX(grand_total), 0) as max_order_value
            FROM {$orderTable}
            WHERE status != 'canceled'";

        $result = $connection->fetchRow($query);

        return [
            'total_revenue' => number_format((float)$result['total_revenue'], 2),
            'avg_order_value' => number_format((float)$result['avg_order_value'], 2),
            'max_order_value' => number_format((float)$result['max_order_value'], 2)
        ];
    }

    /**
     * Get all dashboard statistics
     *
     * @return array
     */
    public function getAllStats(): array
    {
        return [
            'today' => $this->getTodayStats(),
            'week' => $this->getWeekStats(),
            'month' => $this->getMonthStats(),
            'status_distribution' => $this->getOrderStatusDistribution(),
            'top_products' => $this->getTopProducts(5),
            'catalog' => $this->getCatalogStats(),
            'customers' => $this->getCustomerStats(),
            'recent_orders' => $this->getRecentOrders(10),
            'daily_counts' => $this->getDailyOrderCounts(7),
            'online_customers' => $this->getOnlineCustomers(),
            'total_orders' => $this->getTotalOrdersCount(),
            'rma' => $this->getRMAStats(),
            'revenue' => $this->getRevenueStats()
        ];
    }
}
