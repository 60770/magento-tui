<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
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
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Style\Modifier;

use PhpTui\Tui\Text\Span;

class CacheScreen extends BaseScreen
{
    private array $cacheList = [];
    private ?TuiContext $context = null;

    protected function getMaxItems(): int
    {
        if ($this->context === null) {
            return 0;
        }
        return count($this->context->cacheService->getAllCacheTypes());
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;
        $this->cacheList = $context->cacheService->getAllCacheTypes();
        $stats = $context->cacheService->getCacheStatistics();

        // Build table rows with colored status
        $rows = array_map(function (array $cache, int $index) {
            $statusStyle = $cache['enabled'] 
                ? Style::default()->fg(AnsiColor::Green)
                : Style::default()->fg(AnsiColor::Red);
            
            $statusText = $cache['enabled'] ? 'Enabled' : 'Disabled';
            
            return TableRow::fromCells(
                TableCell::fromString($cache['id']),
                TableCell::fromLine(
                    Line::fromSpan(Span::styled($statusText, $statusStyle))
                )
            );
        }, $this->cacheList, array_keys($this->cacheList));

        // Create header
        $header = TableRow::fromCells(
            TableCell::fromLine(Line::fromString(' Cache Type ')->bold()),
            TableCell::fromLine(Line::fromString(' Status ')->bold())
        );

        $title = sprintf(
            ' Cache Management [%d enabled / %d disabled] ',
            $stats['enabled'],
            $stats['disabled']
        );

        // Create table state with selected row
        $tableState = new \PhpTui\Tui\Extension\Core\Widget\Table\TableState(
            offset: 0,
            selected: $this->selectedIndex
        );

        $table = TableWidget::default()
            ->header($header)
            ->widths(Constraint::percentage(70), Constraint::percentage(30))
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::min(10),
                        Constraint::length(10)
                    )
                    ->widgets(
                        BlockWidget::default()
                            ->borders(Borders::ALL)
                            ->borderType(BorderType::Rounded)
                            ->titles(Title::fromLine(Line::fromString(' Cache Types ')->yellow()))
                            ->widget($table),
                        $this->renderActions()
                    )
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;
        $this->cacheList = $context->cacheService->getAllCacheTypes();

        // Convert to lowercase for letter comparison only
        $lowerKey = strtolower($key);

        if ($lowerKey === 'f' && isset($this->cacheList[$this->selectedIndex])) {
            $cacheId = $this->cacheList[$this->selectedIndex]['id'];
            $context->cacheService->flushCache($cacheId);
            return null;
        }

        if ($lowerKey === 't' && isset($this->cacheList[$this->selectedIndex])) {
            $cacheId = $this->cacheList[$this->selectedIndex]['id'];
            $context->cacheService->toggleCache($cacheId);
            return null;
        }

        if ($lowerKey === 'e' && isset($this->cacheList[$this->selectedIndex])) {
            $cacheId = $this->cacheList[$this->selectedIndex]['id'];
            $context->cacheService->enableCache($cacheId);
            return null;
        }

        if ($lowerKey === 'd' && isset($this->cacheList[$this->selectedIndex])) {
            $cacheId = $this->cacheList[$this->selectedIndex]['id'];
            $context->cacheService->disableCache($cacheId);
            return null;
        }

        if ($lowerKey === 'a') {
            $context->cacheService->enableAllCaches();
            return null;
        }

        if ($lowerKey === 'x') {
            $context->cacheService->disableAllCaches();
            return null;
        }

        // Pass original key to parent for arrow key handling
        return parent::handleInput($key, $context);
    }

    private function renderActions(): Widget
    {
        $actions = [
            '[T] Toggle selected cache',
            '[E] Enable selected cache',
            '[D] Disable selected cache',
            '[A] Enable all caches',
            '[X] Disable all caches',
            '[F] Flush selected cache',
            '[ESC/q] Back to main menu',
        ];

        $items = array_map(fn(string $action) => ListItem::fromString($action), $actions);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Actions ')->yellow()))
            ->widget(
                ListWidget::default()->items(...$items)
            );
    }
}
