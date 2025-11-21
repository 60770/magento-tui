<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Text\Span;

class DashboardScreen extends BaseScreen
{
    private array $dashboardData = [];
    private float $lastDataRefresh = 0;
    private const REFRESH_INTERVAL = 3.0; // Refresh data every 3 seconds

    public function needsAutoRefresh(): bool
    {
        return true;
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        // Only refresh data if enough time has passed
        $currentTime = microtime(true);
        if ($currentTime - $this->lastDataRefresh >= self::REFRESH_INTERVAL) {
            $this->loadDashboardData($context);
            $this->lastDataRefresh = $currentTime;
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Live Dashboard - Real-Time Statistics ')->yellow()->bold()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::percentage(50),
                        Constraint::percentage(50)
                    )
                    ->widgets(
                        $this->renderTopPanels($area),
                        $this->renderBottomPanels($area)
                    )
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        if ($key === 'r' || $key === 'R') {
            $this->loadDashboardData($context);
            return null;
        }

        return parent::handleInput($key, $context);
    }

    private function renderTopPanels(Area $area): Widget
    {
        $orders = $this->dashboardData['orders'] ?? [];
        $system = $this->dashboardData['system'] ?? [];
        $db = $this->dashboardData['database'] ?? [];
        $cache = $this->dashboardData['cache'] ?? [];

        // Use a 2x2 grid for better responsiveness
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::percentage(50),
                Constraint::percentage(50)
            )
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::percentage(50),
                        Constraint::percentage(50)
                    )
                    ->widgets(
                        $this->createPanel(
                            ' Live Metrics ',
                            [
                                'Online Customers' => $orders['online_customers'] ?? 0,
                                'Total Orders' => $orders['total_orders'] ?? 0,
                                'Cache Enabled' => sprintf('%d/%d', $cache['enabled'] ?? 0, $cache['total'] ?? 0),
                                'DB Tables' => $db['table_count'] ?? 'N/A',
                                'DB Size' => $db['total_size'] ?? 'N/A',
                            ]
                        ),
                        $this->createPanel(
                            ' Orders & Revenue (Today) ',
                            [
                                'Order Count' => $orders['today']['order_count'] ?? 0,
                                'Total Revenue' => 'EUR ' . ($orders['today']['total_revenue'] ?? '0.00'),
                                'Avg Order Value' => 'EUR ' . ($orders['today']['avg_order_value'] ?? '0.00'),
                            ]
                        )
                    ),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::percentage(50),
                        Constraint::percentage(50)
                    )
                    ->widgets(
                        $this->createPanel(
                            ' Customers & Catalog ',
                            [
                                'Total Customers' => number_format((float)($orders['customers']['total_customers'] ?? 0)),
                                'New This Month' => number_format((float)($orders['customers']['new_this_month'] ?? 0)),
                                'Total Products' => number_format((float)($orders['catalog']['total_products'] ?? 0)),
                                'Enabled Products' => number_format((float)($orders['catalog']['enabled_products'] ?? 0)),
                            ]
                        ),
                        $this->createPanel(
                            ' System Info ',
                            [
                                'PHP Version' => $system['php_version'] ?? 'N/A',
                                'Magento Version' => $system['magento_version'] ?? 'N/A',
                                'Memory Usage' => $system['current_memory_usage'] ?? 'N/A',
                            ]
                        )
                    )
            );
    }

    private function renderBottomPanels(Area $area): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(60),
                Constraint::percentage(40)
            )
            ->widgets(
                $this->createRecentOrdersPanel($this->dashboardData['orders']['recent_orders'] ?? []),
                $this->createTopProductsPanel($this->dashboardData['orders']['top_products'] ?? [])
            );
    }

    private function createPanel(string $title, array $data): Widget
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = TableRow::fromCells(
                TableCell::fromString($key),
                TableCell::fromString((string)$value)
            );
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()->bold()))
            ->widget(
                TableWidget::default()
                    ->widths(Constraint::percentage(50), Constraint::percentage(50))
                    ->rows(...$rows)
            );
    }

    private function createRecentOrdersPanel(array $orders): Widget
    {
        $rows = array_map(function (array $order) {
            return TableRow::fromCells(
                TableCell::fromString($order['increment_id'] ?? ''),
                TableCell::fromString($order['status'] ?? ''),
                TableCell::fromString('EUR ' . number_format((float)($order['grand_total'] ?? 0), 2))
            );
        }, array_slice($orders, 0, 10));

        array_unshift($rows, TableRow::fromCells(
            TableCell::fromLine(Line::fromString(' ID ')->bold()),
            TableCell::fromLine(Line::fromString(' Status ')->bold()),
            TableCell::fromLine(Line::fromString(' Total ')->bold())
        ));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Recent Orders ')->yellow()->bold()))
            ->widget(
                TableWidget::default()
                    ->widths(
                        Constraint::percentage(40),
                        Constraint::percentage(30),
                        Constraint::percentage(30)
                    )
                    ->rows(...$rows)
            );
    }

    private function createTopProductsPanel(array $products): Widget
    {
        $rows = array_map(function (array $product) {
            return TableRow::fromCells(
                TableCell::fromString($product['name'] ?? ''),
                TableCell::fromString('x' . ($product['total_qty'] ?? 0))
            );
        }, array_slice($products, 0, 10));

        array_unshift($rows, TableRow::fromCells(
            TableCell::fromLine(Line::fromString(' Product ')->bold()),
            TableCell::fromLine(Line::fromString(' Qty ')->bold())
        ));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Top Products (30 days) ')->yellow()->bold()))
            ->widget(
                TableWidget::default()
                    ->widths(Constraint::percentage(80), Constraint::percentage(20))
                    ->rows(...$rows)
            );
    }

    private function loadDashboardData(TuiContext $context): void
    {
        try {
            $this->dashboardData = [
                'system' => $context->statsService->getSystemInfo(),
                'database' => $context->statsService->getDatabaseStats(),
                'orders' => $context->orderStatsService->getAllStats(),
                'cache' => $context->cacheService->getCacheStatistics(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            $this->dashboardData = [
                'system' => ['error' => 'Failed to load system info'],
                'database' => ['error' => 'Failed to load database stats'],
                'orders' => [
                    'today' => ['order_count' => 0, 'total_revenue' => '0.00', 'avg_order_value' => '0.00'],
                    'week' => ['order_count' => 0, 'total_revenue' => '0.00'],
                    'month' => ['order_count' => 0, 'total_revenue' => '0.00'],
                    'recent_orders' => [],
                    'top_products' => [],
                    'catalog' => ['total_products' => 0, 'enabled_products' => 0],
                    'customers' => ['total_customers' => 0, 'new_this_month' => 0]
                ],
                'cache' => ['total' => 0, 'enabled' => 0, 'disabled' => 0],
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
        }
    }
}
