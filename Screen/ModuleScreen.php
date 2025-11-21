<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Style\Modifier;

class ModuleScreen extends BaseScreen
{
    private array $modules = [];
    private ?TuiContext $context = null;
    private string $lastMessage = '';
    private string $filterText = '';
    private bool $showEnabledOnly = false;
    private bool $showDisabledOnly = false;
    private ?array $lastError = null;
    
    // Input states
    private bool $isFiltering = false;
    private bool $isShowingError = false;
    private string $filterInput = '';

    protected function getMaxItems(): int
    {
        return count($this->getFilteredModules());
    }

    /**
     * Get filtered module list
     *
     * @return array
     */
    private function getFilteredModules(): array
    {
        $filtered = $this->modules;

        // Apply enabled/disabled filter
        if ($this->showEnabledOnly) {
            $filtered = array_filter($filtered, fn($m) => $m['enabled']);
        } elseif ($this->showDisabledOnly) {
            $filtered = array_filter($filtered, fn($m) => !$m['enabled']);
        }

        // Apply text filter
        if (!empty($this->filterText)) {
            $filtered = array_filter($filtered, function($m) {
                return stripos($m['name'], $this->filterText) !== false;
            });
        }

        return array_values($filtered); // Re-index array
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;
        $this->modules = $context->moduleService->getAllModules();

        if ($this->isShowingError) {
            return $this->renderErrorModal();
        }

        if ($this->isFiltering) {
            return $this->renderFilterInput();
        }

        return $this->renderModuleList($area, $context);
    }

    private function renderModuleList(Area $area, TuiContext $context): Widget
    {
        $stats = $context->moduleService->getModuleStatistics();
        $filteredModules = $this->getFilteredModules();

        $rows = array_map(function (array $module, int $index) {
            $statusText = $module['enabled'] ? 'Enabled' : 'Disabled';
            $statusColor = $module['enabled'] ? AnsiColor::Green : AnsiColor::Red;

            return TableRow::fromCells(
                TableCell::fromString($module['name']),
                TableCell::fromLine(
                    Line::fromSpan(
                        Span::styled($statusText, Style::default()->fg($statusColor))
                    )
                ),
                TableCell::fromString($module['setup_version'] ?? 'N/A')
            );
        }, $filteredModules, array_keys($filteredModules));

        $header = TableRow::fromCells(
            TableCell::fromString('Module Name'),
            TableCell::fromString('Status'),
            TableCell::fromString('Version')
        );

        $tableState = new TableState(
            offset: 0,
            selected: $this->selectedIndex
        );

        $table = TableWidget::default()
            ->header($header)
            ->widths(
                Constraint::percentage(60),
                Constraint::percentage(20),
                Constraint::percentage(20)
            )
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        $title = sprintf(' Module Management [Total: %d | Enabled: %d | Disabled: %d',
            $stats['total'],
            $stats['enabled'],
            $stats['disabled']
        );

        // Add filter info to title
        if (!empty($this->filterText) || $this->showEnabledOnly || $this->showDisabledOnly) {
            $title .= ' | Showing: ' . count($filteredModules);
        }

        $title .= '] ';

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
                        Constraint::length(6)
                    )
                    ->widgets(
                        $table,
                        $this->renderActions()
                    )
            );
    }

    private function renderActions(): Widget
    {
        $actions = [
            '[Enter] Toggle module status | [R] Refresh list',
            '[F] Search by name | [E] Show enabled only | [D] Show disabled only',
            '[C] Clear filters',
        ];

        // Show active filters
        $activeFilters = [];
        if (!empty($this->filterText)) {
            $activeFilters[] = "Text: '$this->filterText'";
        }
        if ($this->showEnabledOnly) {
            $activeFilters[] = 'Enabled only';
        }
        if ($this->showDisabledOnly) {
            $activeFilters[] = 'Disabled only';
        }

        if (!empty($activeFilters)) {
            $actions[] = '✓ Active filters: ' . implode(', ', $activeFilters);
        }

        if (!empty($this->lastMessage)) {
            $actions[] = '→ ' . $this->lastMessage;
        }

        // Show [V] option if there's an error with details
        if ($this->lastError !== null) {
            $actions[] = '⚠ Error details available - press [V] to view';
        }

        $actions[] = '[ESC/q] Back to main menu';

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

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;

        // Handle Error Modal Input
        if ($this->isShowingError) {
            $this->isShowingError = false;
            return null;
        }

        // Handle Filter Input
        if ($this->isFiltering) {
            if ($key === "\n" || $key === "\r") {
                $this->filterText = $this->filterInput;
                $this->isFiltering = false;
                $this->selectedIndex = 0;
                $this->lastMessage = "✓ Filtering by: '{$this->filterText}'";
                return null;
            }

            if ($key === "\x1b") { // ESC
                $this->isFiltering = false;
                return null;
            }

            if (ord($key) === 127) { // Backspace
                $this->filterInput = substr($this->filterInput, 0, -1);
            } elseif (strlen($key) === 1 && ctype_print($key)) {
                $this->filterInput .= $key;
            }
            return null;
        }

        $lowerKey = strtolower($key);

        // Filter by name
        if ($lowerKey === 'f') {
            $this->isFiltering = true;
            $this->filterInput = $this->filterText;
            return null;
        }

        // Show enabled only
        if ($lowerKey === 'e') {
            $this->showEnabledOnly = !$this->showEnabledOnly;
            if ($this->showEnabledOnly) {
                $this->showDisabledOnly = false;
                $this->lastMessage = '✓ Showing enabled modules only';
            } else {
                $this->lastMessage = 'Showing all modules';
            }
            $this->selectedIndex = 0; // Reset selection
            return null;
        }

        // Show disabled only
        if ($lowerKey === 'd') {
            $this->showDisabledOnly = !$this->showDisabledOnly;
            if ($this->showDisabledOnly) {
                $this->showEnabledOnly = false;
                $this->lastMessage = '✓ Showing disabled modules only';
            } else {
                $this->lastMessage = 'Showing all modules';
            }
            $this->selectedIndex = 0; // Reset selection
            return null;
        }

        // Clear filters
        if ($lowerKey === 'c') {
            $this->filterText = '';
            $this->showEnabledOnly = false;
            $this->showDisabledOnly = false;
            $this->selectedIndex = 0;
            $this->lastMessage = 'All filters cleared';
            return null;
        }

        // View error details
        if ($lowerKey === 'v' && $this->lastError !== null) {
            $this->isShowingError = true;
            return null;
        }

        // Refresh list
        if ($lowerKey === 'r') {
            $this->modules = $context->moduleService->getAllModules();
            $this->lastMessage = 'Module list refreshed';
            return null;
        }

        // Toggle module status
        $filteredModules = $this->getFilteredModules();
        if (($key === "\n" || $key === "\r") && isset($filteredModules[$this->selectedIndex])) {
            $module = $filteredModules[$this->selectedIndex];
            $moduleName = $module['name'];

            // Don't allow disabling Tidycode_TUI
            if ($moduleName === 'Tidycode_TUI') {
                $this->lastMessage = '⚠ Cannot disable Tidycode_TUI module';
                return null;
            }

            if ($module['enabled']) {
                $result = $context->moduleService->disableModule($moduleName);
            } else {
                $result = $context->moduleService->enableModule($moduleName);
            }

            if ($result['success']) {
                $this->lastMessage = '✓ ' . $result['message'];
                $this->lastError = null;

                // Refresh module list
                $this->modules = $context->moduleService->getAllModules();
            } else {
                $this->lastMessage = '✗ ' . $result['message'];

                // Save error details if available
                if (isset($result['has_details']) && $result['has_details']) {
                    $this->lastError = [
                        'module' => $moduleName,
                        'action' => $module['enabled'] ? 'disable' : 'enable',
                        'output' => $result['full_output'] ?? 'No details available'
                    ];
                    $this->lastMessage .= ' - Press [V] to view details';
                } else {
                    $this->lastError = null;
                }
            }

            return null;
        }

        // Pass original key to parent for arrow key handling
        return parent::handleInput($key, $context);
    }

    private function renderFilterInput(): Widget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Filter Modules ')->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    Span::fromString('Enter module name: '),
                    Span::styled($this->filterInput . '█', Style::default()->fg(AnsiColor::Cyan))
                )))
            );
    }

    private function renderErrorModal(): Widget
    {
        if ($this->lastError === null) {
            return ParagraphWidget::fromText(Text::fromString('No error details available'));
        }

        $text = sprintf(
            "Module: %s\nAction: %s\n\nError Output:\n%s\n\nPress any key to return...",
            $this->lastError['module'],
            $this->lastError['action'],
            $this->lastError['output']
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Red))
            ->titles(Title::fromLine(Line::fromString(' Error Details ')->red()->addModifier(Modifier::BOLD)))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($text))
            );
    }

}
