<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;
use PhpTui\Tui\Style\Modifier;

use PhpTui\Tui\Text\Span;

class ConfigScreen extends BaseScreen
{
    private array $configList = [];
    private array $allConfigs = [];
    private array $filteredConfigs = [];
    private bool $editMode = false;
    private bool $filterMode = false;
    private string $editBuffer = '';
    private string $filterText = '';
    private ?array $editingConfig = null;
    private int $currentPage = 0;
    private int $itemsPerPage = 20;
    private int $totalPages = 0;

    protected function getMaxItems(): int
    {
        return count($this->configList);
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        // If in edit mode, handle editing
        if ($this->editMode) {
            return $this->handleEditInput($key, $context);
        }

        // If in filter mode, handle filtering
        if ($this->filterMode) {
            return $this->handleFilterInput($key);
        }

        // Handle '/' to enter filter mode
        if ($key === '/') {
            $this->filterMode = true;
            $this->filterText = '';
            return null;
        }

        // Handle Page Up/Down
        if ($key === "\033[5~") { // Page Up
            if ($this->currentPage > 0) {
                $this->currentPage--;
                $this->selectedIndex = 0;
            }
            return null;
        }
        if ($key === "\033[6~") { // Page Down
            if ($this->currentPage < $this->totalPages - 1) {
                $this->currentPage++;
                $this->selectedIndex = 0;
            }
            return null;
        }

        // Handle Enter to start editing
        if ($key === "\n" || $key === "\r") {
            if (isset($this->configList[$this->selectedIndex])) {
                $this->editingConfig = $this->configList[$this->selectedIndex];
                $this->editBuffer = (string)($this->editingConfig['value'] ?? '');
                $this->editMode = true;
                return null;
            }
        }

        // Default navigation
        return parent::handleInput($key, $context);
    }

    private function handleEditInput(string $key, TuiContext $context): ?string
    {
        if ($key === "\e" || $key === "\033") {
            // ESC: cancel editing
            $this->editMode = false;
            $this->editBuffer = '';
            $this->editingConfig = null;
            return null;
        }

        if ($key === "\n" || $key === "\r") {
            // Enter: save
            if ($this->editingConfig) {
                $context->configService->saveConfigValue(
                    $this->editingConfig['path'],
                    $this->editBuffer,
                    $this->editingConfig['scope'] ?? 'default',
                    (int)($this->editingConfig['scope_id'] ?? 0)
                );
            }
            $this->editMode = false;
            $this->editBuffer = '';
            $this->editingConfig = null;
            return null;
        }

        if ($key === "\x7f" || $key === "\x08") {
            // Backspace
            if (strlen($this->editBuffer) > 0) {
                $this->editBuffer = substr($this->editBuffer, 0, -1);
            }
            return null;
        }

        // Regular character
        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) < 127) {
            $this->editBuffer .= $key;
        }

        return null;
    }

    private function handleFilterInput(string $key): ?string
    {
        if ($key === "\e" || $key === "\033") {
            // ESC: cancel filter
            $this->filterMode = false;
            $this->filterText = '';
            $this->filteredConfigs = [];
            $this->currentPage = 0;
            return null;
        }

        if ($key === "\n" || $key === "\r") {
            // Enter: apply filter
            $this->filterMode = false;
            $this->currentPage = 0;
            $this->selectedIndex = 0;
            return null;
        }

        if ($key === "\x7f" || $key === "\x08") {
            // Backspace
            if (strlen($this->filterText) > 0) {
                $this->filterText = substr($this->filterText, 0, -1);
            }
            return null;
        }

        // Regular character
        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) < 127) {
            $this->filterText .= $key;
        }

        return null;
    }


    public function render(Area $area, TuiContext $context): Widget
    {
        // Get all configurations
        $this->allConfigs = $context->configService->getAllConfigurations();
        
        // Apply filter if filter text is set
        if (!empty($this->filterText)) {
            $filterLower = strtolower($this->filterText);
            $this->filteredConfigs = array_filter($this->allConfigs, function($config) use ($filterLower) {
                $path = strtolower($config['path'] ?? '');
                $value = strtolower((string)($config['value'] ?? ''));
                return str_contains($path, $filterLower) || str_contains($value, $filterLower);
            });
        } else {
            $this->filteredConfigs = $this->allConfigs;
        }
        
        // Calculate pagination based on filtered results
        $this->totalPages = (int)ceil(count($this->filteredConfigs) / $this->itemsPerPage);

        // Get current page
        $offset = $this->currentPage * $this->itemsPerPage;
        $this->configList = array_slice($this->filteredConfigs, $offset, $this->itemsPerPage);

        // Build table rows
        $rows = [];
        foreach ($this->configList as $index => $config) {
            // Determine source color
            $sourceText = $config['source'] ?? 'database';
            $sourceColor = match($sourceText) {
                'env.php' => AnsiColor::Red,
                'config.php' => AnsiColor::Yellow,
                'database' => AnsiColor::Green,
                default => AnsiColor::White
            };

            $dbValue = (string)($config['value'] ?? '');
            $finalValue = (string)($config['final_value'] ?? $dbValue);

            // Highlight if overridden
            $finalValueStyle = ($config['is_overridden'] ?? false)
                ? Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)
                : Style::default()->fg(AnsiColor::White);

            $rows[] = TableRow::fromCells(
                TableCell::fromString($config['path'] ?? ''),
                TableCell::fromString($dbValue),
                TableCell::fromLine(
                    Line::fromSpan(Span::styled($sourceText, Style::default()->fg($sourceColor)))
                ),
                TableCell::fromLine(
                    Line::fromSpan(Span::styled(substr($finalValue, 0, 30), $finalValueStyle))
                )
            );
        }

        // Create header
        $header = TableRow::fromCells(
            TableCell::fromLine(Line::fromString(' Path ')->bold()),
            TableCell::fromLine(Line::fromString(' DB Value ')->bold()),
            TableCell::fromLine(Line::fromString(' Source ')->bold()),
            TableCell::fromLine(Line::fromString(' Final Value ')->bold())
        );

        $pageInfo = sprintf(' [Page %d/%d]', $this->currentPage + 1, max(1, $this->totalPages));
        $filterInfo = !empty($this->filterText) ? sprintf(' [Filter: "%s" - %d results]', $this->filterText, count($this->filteredConfigs)) : '';
        
        $title = $this->editMode
            ? sprintf(' Editing: %s [Enter=Save ESC=Cancel] ', $this->editingConfig['path'] ?? '')
            : ($this->filterMode
                ? ' Filter Mode [Type to search, Enter=Apply, ESC=Cancel] '
                : ' Configuration Management [/=Filter Enter=Edit PgUp/PgDn=Page ESC/q=Back]' . $filterInfo . $pageInfo . ' ');

        // Create table state with selected row
        $tableState = new TableState(
            offset: 0,
            selected: $this->editMode ? null : $this->selectedIndex
        );

        $widget = TableWidget::default()
            ->header($header)
            ->widths(
                Constraint::percentage(40),  // Path
                Constraint::percentage(20),  // DB Value
                Constraint::percentage(15),  // Source
                Constraint::percentage(25)   // Final Value
            )
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        // If in filter mode, show filter input at the bottom
        if ($this->filterMode) {
            return GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(
                    Constraint::min(10),
                    Constraint::length(5)
                )
                ->widgets(
                    BlockWidget::default()
                        ->borders(Borders::ALL)
                        ->borderType(BorderType::Rounded)
                        ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                        ->titles(Title::fromLine(Line::fromString($title)->yellow()))
                        ->widget($widget),
                    BlockWidget::default()
                        ->borders(Borders::ALL)
                        ->borderType(BorderType::Rounded)
                        ->borderStyle(Style::default()->fg(AnsiColor::Magenta))
                        ->titles(Title::fromLine(Line::fromString(' Filter (type to search) ')->magenta()->bold()))
                        ->widget(
                            ParagraphWidget::fromText(Text::fromString($this->filterText . '█'))
                        )
                );
        }

        // If in edit mode, show edit buffer at the bottom
        if ($this->editMode) {
            return GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(
                    Constraint::min(10),
                    Constraint::length(5)
                )
                ->widgets(
                    BlockWidget::default()
                        ->borders(Borders::ALL)
                        ->borderType(BorderType::Rounded)
                        ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                        ->titles(Title::fromLine(Line::fromString($title)->yellow()))
                        ->widget($widget),
                    BlockWidget::default()
                        ->borders(Borders::ALL)
                        ->borderType(BorderType::Rounded)
                        ->borderStyle(Style::default()->fg(AnsiColor::Green))
                        ->titles(Title::fromLine(Line::fromString(' New Value (type to edit) ')->green()->bold()))
                        ->widget(
                            ParagraphWidget::fromText(Text::fromString($this->editBuffer . '█'))
                        )
                );
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget($widget);
    }
}
