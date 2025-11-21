<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
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
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Style\Modifier;
use Magento\Framework\Indexer\StateInterface;

class IndexScreen extends BaseScreen
{
    private array $indexerList = [];
    private ?TuiContext $context = null;

    protected function getMaxItems(): int
    {
        if ($this->context === null) {
            return 0;
        }
        return count($this->context->indexService->getAllIndexers());
    }

    public function needsAutoRefresh(): bool
    {
        // Auto-refresh when reindexing is in progress
        return $this->context !== null && $this->context->indexService->isReindexing();
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;
        $this->indexerList = $context->indexService->getAllIndexers();
        $stats = $context->indexService->getIndexerStatistics();

        // Build table rows with colored status
        $rows = array_map(function (array $indexer, int $index) use ($context) {
            $statusText = $context->indexService->getStatusText($indexer['status']);
            $statusStyle = match ($indexer['status']) {
                StateInterface::STATUS_VALID => Style::default()->fg(AnsiColor::Green),
                StateInterface::STATUS_INVALID => Style::default()->fg(AnsiColor::Red),
                StateInterface::STATUS_WORKING => Style::default()->fg(AnsiColor::Yellow),
                default => Style::default()->fg(AnsiColor::White)
            };

            $modeStyle = $indexer['is_scheduled']
                ? Style::default()->fg(AnsiColor::Cyan)
                : Style::default()->fg(AnsiColor::Magenta);

            return TableRow::fromCells(
                TableCell::fromString($indexer['title']),
                TableCell::fromLine(
                    Line::fromSpan(Span::styled($statusText, $statusStyle))
                ),
                TableCell::fromLine(
                    Line::fromSpan(Span::styled($indexer['mode'], $modeStyle))
                )
            );
        }, $this->indexerList, array_keys($this->indexerList));

        // Create header
        $header = TableRow::fromCells(
            TableCell::fromLine(Line::fromString(' Indexer ')->bold()),
            TableCell::fromLine(Line::fromString(' Status ')->bold()),
            TableCell::fromLine(Line::fromString(' Mode ')->bold())
        );

        $title = sprintf(
            ' Index Management [Valid: %d | Invalid: %d | Working: %d] ',
            $stats['valid'],
            $stats['invalid'],
            $stats['working']
        );

        // Create table state with selected row
        $tableState = new \PhpTui\Tui\Extension\Core\Widget\Table\TableState(
            offset: 0,
            selected: $this->selectedIndex
        );

        $table = TableWidget::default()
            ->header($header)
            ->widths(
                Constraint::percentage(50),
                Constraint::percentage(25),
                Constraint::percentage(25)
            )
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        // Check if reindexing is in progress
        $isReindexing = $context->indexService->isReindexing();
        $output = $context->indexService->getReindexOutput();

        $widgets = [
            BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->titles(Title::fromLine(Line::fromString(' Indexers ')->yellow()))
                ->widget($table),
        ];

        $constraints = [Constraint::min(10)];

        // Add progress output if reindexing
        if ($isReindexing || !empty($output)) {
            $widgets[] = $this->renderProgress($output, $isReindexing);
            $constraints[] = Constraint::length(12);
        }

        // Add actions
        $widgets[] = $this->renderActions($isReindexing);
        $constraints[] = Constraint::length(10);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(...$constraints)
                    ->widgets(...$widgets)
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;
        $this->indexerList = $context->indexService->getAllIndexers();

        // Convert to lowercase for letter comparison only
        $lowerKey = strtolower($key);

        // Handle clear output
        if ($lowerKey === 'c') {
            $context->indexService->clearOutput();
            return null;
        }

        // Prevent actions during reindexing (except clear and exit)
        if ($context->indexService->isReindexing()) {
            return parent::handleInput($key, $context);
        }

        if ($lowerKey === 'r' && isset($this->indexerList[$this->selectedIndex])) {
            $indexerId = $this->indexerList[$this->selectedIndex]['id'];
            try {
                $context->indexService->reindex($indexerId);
            } catch (\Exception $e) {
                // Error handling - could add notification system later
            }
            return null;
        }

        if ($lowerKey === 'a') {
            try {
                $context->indexService->reindexAll();
            } catch (\Exception $e) {
                // Error handling
            }
            return null;
        }

        if ($lowerKey === 's' && isset($this->indexerList[$this->selectedIndex])) {
            $indexerId = $this->indexerList[$this->selectedIndex]['id'];
            $currentMode = $this->indexerList[$this->selectedIndex]['is_scheduled'];
            try {
                $context->indexService->setMode($indexerId, !$currentMode);
            } catch (\Exception $e) {
                // Error handling
            }
            return null;
        }

        if ($lowerKey === 'i' && isset($this->indexerList[$this->selectedIndex])) {
            $indexerId = $this->indexerList[$this->selectedIndex]['id'];
            try {
                $context->indexService->resetIndex($indexerId);
            } catch (\Exception $e) {
                // Error handling
            }
            return null;
        }

        // Pass original key to parent for arrow key handling
        return parent::handleInput($key, $context);
    }

    private function renderProgress(string $output, bool $isRunning): Widget
    {
        $titleText = $isRunning ? ' Reindexing in Progress... ⏳ ' : ' Last Reindex Output ';
        $titleColor = $isRunning ? AnsiColor::Yellow : AnsiColor::Green;

        // Get last 8 lines of output
        $lines = explode("\n", $output);
        $lines = array_slice($lines, -8);
        $displayText = implode("\n", $lines);

        if (empty($displayText)) {
            $displayText = $isRunning ? 'Starting reindex process...' : 'No output';
        }

        $titleStyle = Style::default()->fg($titleColor)->addModifier(Modifier::BOLD);
        $titleLine = Line::fromSpan(Span::styled($titleText, $titleStyle));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg($titleColor))
            ->titles(Title::fromLine($titleLine))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($displayText))
                    ->style(Style::default()->fg(AnsiColor::White))
            );
    }

    private function renderActions(bool $isReindexing): Widget
    {
        if ($isReindexing) {
            $actions = [
                '⏳ Reindexing in progress...',
                'Please wait for the process to complete',
                '[C] Clear output',
                '[ESC/q] Back to main menu',
            ];
        } else {
            $actions = [
                '[R] Reindex selected',
                '[A] Reindex all',
                '[S] Switch mode (Save/Schedule)',
                '[I] Invalidate selected',
                '[C] Clear output (if any)',
                '[ESC/q] Back to main menu',
            ];
        }

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
