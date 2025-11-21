<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Text\Text;
use Tidycode\TUI\Service\MaintenanceService;

use PhpTui\Tui\Text\Span;

class MainScreen extends BaseScreen
{
    private const MENU_ITEMS = [
        'config',
        'logs',
        'cache',
        'indexer',
        'deploy',
        'modules',
        'urls',
        'database',
        'stats',
        'maintenance',
    ];

    public function render(Area $area, TuiContext $context): Widget
    {
        $cpuUsage = $this->getCPUUsage();
        $ramInfo = $this->getRAMInfo();
        $maintenanceEnabled = $context->maintenanceService->isEnabled();
        $developerMode = $context->deployService->isDeveloperMode();

        // Calculate logo height based on warnings
        $warningCount = 0;
        if ($maintenanceEnabled) $warningCount++;
        if ($developerMode) $warningCount++;
        $logoHeight = 8 + ($warningCount * 3);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(
                Title::fromLine(Line::fromSpan(Span::styled(' Tidycode TUI ', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD))))
            )
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::length($logoHeight),
                        Constraint::min(10),   // Menu
                        Constraint::length(3)  // Footer
                    )
                    ->widgets(
                        $this->renderLogo($maintenanceEnabled, $developerMode),
                        $this->renderMenu(),
                        $this->renderFooter($cpuUsage, $ramInfo)
                    )
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        // Handle number keys 1-9 for menu items 1-9
        if ($key >= '1' && $key <= '9') {
            $index = (int)$key - 1;
            if (isset(self::MENU_ITEMS[$index])) {
                return self::MENU_ITEMS[$index];
            }
        }

        // Handle '0' for menu item 10
        if ($key === '0') {
            if (isset(self::MENU_ITEMS[9])) {
                return self::MENU_ITEMS[9];
            }
        }

        if ($key === "\n" || $key === "\r") {
            if (isset(self::MENU_ITEMS[$this->selectedIndex])) {
                return self::MENU_ITEMS[$this->selectedIndex];
            }
        }

        if ($key === 'q') {
            return 'quit';
        }
        
        return parent::handleInput($key, $context);
    }

    protected function getMaxItems(): int
    {
        return count(self::MENU_ITEMS);
    }

    private function renderLogo(bool $maintenanceEnabled, bool $developerMode): Widget
    {
        $logoText = $this->getTidycodeLogo();

        $logoWidget = ParagraphWidget::fromText(Text::fromString($logoText))
            ->alignment(HorizontalAlignment::Center)
            ->style(Style::default()->fg(AnsiColor::Magenta)->addModifier(Modifier::BOLD));

        $warnings = [];

        if ($maintenanceEnabled) {
            $warnings[] = BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Thick)
                ->borderStyle(Style::default()->fg(AnsiColor::Red))
                ->widget(
                    ParagraphWidget::fromText(Text::fromString("!! WARNING: MAINTENANCE MODE IS ENABLED !!"))
                        ->alignment(HorizontalAlignment::Center)
                        ->style(Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD))
                );
        }

        if ($developerMode) {
            $warnings[] = BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Thick)
                ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
                ->widget(
                    ParagraphWidget::fromText(Text::fromString("!! DEVELOPER MODE IS ACTIVE !!"))
                        ->alignment(HorizontalAlignment::Center)
                        ->style(Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD))
                );
        }

        if (!empty($warnings)) {
            $constraints = array_fill(0, count($warnings), Constraint::length(3));
            $constraints[] = Constraint::min(1);

            $widgets = array_merge($warnings, [$logoWidget]);

            return GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(...$constraints)
                ->widgets(...$widgets);
        }

        return $logoWidget;
    }

    private function renderMenu(): Widget
    {
        $menuItems = [
            'Configuration Management',
            'Log Monitoring',
            'Cache Management',
            'Index Management',
            'Deployment Management',
            'Module Management',
            'URL Management',
            'Database Management',
            'System Statistics',
            'Maintenance Mode'
        ];
        
        $items = array_map(function (string $item, int $index) {
            return ListItem::fromString(sprintf('[%d] %s', $index + 1, $item));
        }, $menuItems, array_keys($menuItems));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::White))
            ->titles(Title::fromLine(Line::fromString(' Main Menu ')->green()->bold()))
            ->widget(
                ListWidget::default()
                    ->items(...$items)
                    ->select($this->selectedIndex)
                    ->highlightSymbol('► ')
                    ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan)->addModifier(Modifier::BOLD))
            );
    }

    private function renderFooter(float $cpuUsage, array $ramInfo): Widget
    {
        $footerText = sprintf(
            " CPU: %.1f%% | RAM: %s / %s (%.1f%%) | [Q]uit | [Enter] Select ",
            $cpuUsage,
            $ramInfo['used'],
            $ramInfo['total'],
            $ramInfo['percent']
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::White))
            ->titles(Title::fromLine(Line::fromString(' System Info ')->green()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($footerText))
                    ->alignment(HorizontalAlignment::Center)
                    ->style(Style::default()->fg(AnsiColor::Yellow))
            );
    }

    private function getTidycodeLogo(): string
    {
        return <<<'ASCII'
  _______ _     _                     _        _______ _    _ _____ 
 |__   __(_)   | |                   | |      |__   __| |  | |_   _|
    | |   _  __| |_   _  ___ ___   __| | ___     | |  | |  | | | |  
    | |  | |/ _` | | | |/ __/ _ \ / _` |/ _ \    | |  | |  | | | |  
    | |  | | (_| | |_| | (_| (_) | (_| |  __/    | |  | |__| |_| |_ 
    |_|  |_|\__,_|\__, |\___\___/ \__,_|\___|    |_|   \____/|_____|
                   __/ |                                            
                  |___/                                             
ASCII;
    }

    private function getCPUUsage(): float
    {
        try {
            $load = sys_getloadavg();
            $cores = 1;
            if (is_readable('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cores = count($matches[0]) ?: 1;
            }
            $usage = ($load[0] / $cores) * 100;
            return min($usage, 100.0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function getRAMInfo(): array
    {
        try {
            $free = shell_exec('free -b');
            if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free, $matches)) {
                $total = (int)$matches[1];
                $used = (int)$matches[2];
                $percent = ($used / $total) * 100;

                return [
                    'total' => $this->formatBytes($total),
                    'used' => $this->formatBytes($used),
                    'percent' => $percent
                ];
            }
        } catch (\Exception $e) {
            // Fallback
        }

        return [
            'total' => ini_get('memory_limit'),
            'used' => $this->formatBytes(memory_get_usage(true)),
            'percent' => 50.0
        ];
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
