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

class LogsScreen extends BaseScreen
{
    private array $logFiles = [];
    private bool $viewMode = false;
    private ?string $currentLogFile = null;
    private int $scrollOffset = 0;
    private int $linesPerPage = 20;

    // Input state
    private bool $isInputMode = false;
    private string $inputModeType = ''; // 'include', 'exclude', 'search'
    private string $inputBuffer = '';
    private string $lastSearchPattern = '';
    private array $searchResults = [];
    private bool $showingSearchResults = false;

    protected function getMaxItems(): int
    {
        return $this->viewMode ? 0 : count($this->logFiles);
    }

    public function needsAutoRefresh(): bool
    {
        return $this->viewMode && $this->context !== null && $this->context->logService->isTailing();
    }

    private ?TuiContext $context = null;

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;

        // Handle Search Results View
        if ($this->showingSearchResults) {
            if ($key === "\e" || $key === "\033" || $key === 'q' || $key === 'Q' || $key === "\n") {
                $this->showingSearchResults = false;
                $this->searchResults = [];
                return null;
            }
            return null;
        }

        // Handle Input Mode
        if ($this->isInputMode) {
            if ($key === "\n" || $key === "\r") {
                $input = trim($this->inputBuffer);
                if (!empty($input)) {
                    if ($this->inputModeType === 'include') {
                        $context->logService->addIncludeFilter($input);
                    } elseif ($this->inputModeType === 'exclude') {
                        $context->logService->addExcludeFilter($input);
                    } elseif ($this->inputModeType === 'search') {
                        $this->lastSearchPattern = $input;
                        $this->searchResults = $context->logService->searchAllLogs($input);
                        $this->showingSearchResults = true;
                    }
                }
                $this->isInputMode = false;
                $this->inputBuffer = '';
                return null;
            }

            if ($key === "\e" || $key === "\033") {
                $this->isInputMode = false;
                $this->inputBuffer = '';
                return null;
            }

            if (ord($key) === 127) { // Backspace
                $this->inputBuffer = substr($this->inputBuffer, 0, -1);
            } elseif (strlen($key) === 1 && ctype_print($key)) {
                $this->inputBuffer .= $key;
            }
            return null;
        }

        // Handle quit/escape key
        if ($key === 'q' || $key === 'Q' || $key === "\e" || $key === "\033") {
            if ($this->viewMode) {
                // Stop tailing and exit view mode
                $context->logService->stopTail();
                $this->viewMode = false;
                $this->currentLogFile = null;
                $this->scrollOffset = 0;
                return null;
            } else {
                // Quit to main menu
                return 'main';
            }
        }

        if ($this->viewMode) {
            $lowerKey = strtolower($key);

            // Toggle tail mode
            if ($lowerKey === 't') {
                if ($context->logService->isTailing()) {
                    $context->logService->stopTail();
                } else {
                    $context->logService->startTail($this->currentLogFile, 50);
                }
                return null;
            }

            // Quick include filters (show ONLY these)
            if ($lowerKey === '1') {
                $context->logService->addIncludeFilter('ERROR');
                return null;
            }
            if ($lowerKey === '2') {
                $context->logService->addIncludeFilter('CRITICAL');
                return null;
            }
            if ($lowerKey === '3') {
                $context->logService->addIncludeFilter('WARNING');
                return null;
            }
            if ($lowerKey === '4') {
                $context->logService->addIncludeFilter('EXCEPTION');
                return null;
            }

            // Quick exclude filters (hide these)
            if ($lowerKey === '5') {
                $context->logService->addExcludeFilter('DEBUG');
                return null;
            }
            if ($lowerKey === '6') {
                $context->logService->addExcludeFilter('INFO');
                return null;
            }
            if ($lowerKey === '7') {
                $context->logService->addExcludeFilter('deprecated');
                return null;
            }
            if ($lowerKey === '8') {
                $context->logService->addExcludeFilter('notice');
                return null;
            }

            // Custom include filter
            if ($lowerKey === 'i') {
                $this->isInputMode = true;
                $this->inputModeType = 'include';
                $this->inputBuffer = '';
                return null;
            }

            // Custom exclude filter
            if ($lowerKey === 'e') {
                $this->isInputMode = true;
                $this->inputModeType = 'exclude';
                $this->inputBuffer = '';
                return null;
            }

            // Search across all logs
            if ($lowerKey === 's') {
                $this->isInputMode = true;
                $this->inputModeType = 'search';
                $this->inputBuffer = '';
                return null;
            }

            // Clear filters
            if ($lowerKey === 'c') {
                $context->logService->clearFilters();
                return null;
            }

            // Clear output buffer
            if ($lowerKey === 'x') {
                $context->logService->clearTailOutput();
                return null;
            }

            // Scrolling (only when not tailing)
            if (!$context->logService->isTailing()) {
                if ($key === "\033[A") { // Up arrow
                    if ($this->scrollOffset > 0) {
                        $this->scrollOffset--;
                    }
                    return null;
                }
                if ($key === "\033[B") { // Down arrow
                    $lines = $context->logService->getTailOutput();
                    if ($this->scrollOffset < max(0, count($lines) - $this->linesPerPage)) {
                        $this->scrollOffset++;
                    }
                    return null;
                }
            }
            return null;
        }

        // Handle Enter to open log file
        if ($key === "\n" || $key === "\r") {
            if (isset($this->logFiles[$this->selectedIndex])) {
                $logFile = $this->logFiles[$this->selectedIndex];
                $this->currentLogFile = $logFile['name'];
                $this->viewMode = true;
                $this->scrollOffset = 0;

                // Start tailing immediately
                $context->logService->startTail($logFile['name'], 50);
                return null;
            }
        }

        return parent::handleInput($key, $context);
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;

        if ($this->showingSearchResults) {
            return $this->renderSearchResults();
        }

        if ($this->isInputMode) {
            return $this->renderInputModal();
        }

        if ($this->viewMode) {
            return $this->renderLogViewer($context);
        }

        $this->logFiles = $context->logService->getAvailableLogFiles();

        $rows = array_map(function (array $file, int $index) {
            return TableRow::fromCells(
                TableCell::fromString($file['name'] ?? ''),
                TableCell::fromString($this->formatBytes($file['size']))
            );
        }, $this->logFiles, array_keys($this->logFiles));

        $header = TableRow::fromCells(
            TableCell::fromString('File'),
            TableCell::fromString('Size')
        );

        $tableState = new TableState(
            offset: 0,
            selected: $this->selectedIndex
        );

        $widget = TableWidget::default()
            ->header($header)
            ->widths(Constraint::percentage(80), Constraint::percentage(20))
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' Log Monitoring [↑↓=Navigate Enter=View ESC/Q=Back] ')->yellow()))
            ->widget($widget);
    }

    private function renderLogViewer(TuiContext $context): Widget
    {
        $lines = $context->logService->getTailOutput();
        $isTailing = $context->logService->isTailing();

        // Auto-scroll to bottom when tailing
        if ($isTailing) {
            $this->scrollOffset = max(0, count($lines) - $this->linesPerPage);
        }

        $visibleLines = array_slice($lines, $this->scrollOffset, $this->linesPerPage);
        $logContent = implode("\n", $visibleLines);

        if (empty($logContent)) {
            $logContent = $isTailing ? 'Waiting for log entries...' : 'No log entries found';
        }

        $totalLines = count($lines);
        $includeFilters = $context->logService->getIncludeFilters();
        $excludeFilters = $context->logService->getExcludeFilters();

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine($this->buildTitle($isTailing, $totalLines)))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::min(10),
                        Constraint::length(8)
                    )
                    ->widgets(
                        $this->renderLogContent($logContent),
                        $this->renderControls($isTailing, $includeFilters, $excludeFilters)
                    )
            );
    }

    private function buildTitle(bool $isTailing, int $totalLines): Line
    {
        $tailStatus = $isTailing ? ' [LIVE TAIL] ' : ' [STATIC] ';
        $titleText = sprintf(' Log Viewer: %s%s[%d lines] ', $this->currentLogFile ?? '', $tailStatus, $totalLines);

        $color = $isTailing ? AnsiColor::Green : AnsiColor::Yellow;
        return Line::fromSpan(Span::styled($titleText, Style::default()->fg($color)->addModifier(Modifier::BOLD)));
    }

    private function renderLogContent(string $content): Widget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' Log Content ')->cyan()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($content))
                    ->style(Style::default()->fg(AnsiColor::White))
            );
    }

    private function renderControls(bool $isTailing, array $includeFilters, array $excludeFilters): Widget
    {
        $actions = [
            $isTailing ? '[T] Stop tail -f' : '[T] Start tail -f',
            'Quick Include: [1]ERROR [2]CRITICAL [3]WARNING [4]EXCEPTION',
            'Quick Exclude: [5]DEBUG [6]INFO [7]deprecated [8]notice',
            '[I] Custom Include | [E] Custom Exclude | [S] Search all logs',
            '[C] Clear filters | [X] Clear buffer',
        ];

        if (!empty($includeFilters)) {
            $actions[] = '✓ Include: ' . implode(', ', array_map(fn($f) => "'$f'", $includeFilters));
        }

        if (!empty($excludeFilters)) {
            $actions[] = '✗ Exclude: ' . implode(', ', array_map(fn($f) => "'$f'", $excludeFilters));
        }

        if (empty($includeFilters) && empty($excludeFilters)) {
            $actions[] = 'No filters active - showing all lines';
        }

        $actions[] = '[ESC/q] Back to file list';

        $items = array_map(fn(string $action) => ListItem::fromString($action), $actions);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Controls & Quick Filters ')->yellow()))
            ->widget(
                ListWidget::default()->items(...$items)
            );
    }

    private function renderInputModal(): Widget
    {
        $title = match ($this->inputModeType) {
            'include' => ' Custom Include Filter ',
            'exclude' => ' Custom Exclude Filter ',
            'search' => ' Search All Logs ',
            default => ' Input '
        };

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    Span::fromString('Enter pattern: '),
                    Span::styled($this->inputBuffer . '█', Style::default()->fg(AnsiColor::Cyan))
                )))
            );
    }

    private function renderSearchResults(): Widget
    {
        if (empty($this->searchResults)) {
            $content = "No results found for '{$this->lastSearchPattern}'";
        } else {
            $lines = [];
            $lines[] = Line::fromString("Found " . count($this->searchResults) . " matches for '{$this->lastSearchPattern}':");
            $lines[] = Line::fromString("");
            
            foreach ($this->searchResults as $result) {
                $fileDisplay = $result['file'];
                if ($result['is_gzip'] ?? false) {
                    $fileDisplay .= " [GZIP]";
                }
                
                $lines[] = Line::fromSpans(
                    Span::styled("File: " . $fileDisplay, Style::default()->fg(AnsiColor::Cyan)),
                    Span::fromString(" (Line " . $result['line_number'] . ")")
                );
                $lines[] = Line::fromString($result['line']);
                $lines[] = Line::fromSpan(Span::fromString(str_repeat("─", 50))->style(Style::default()->fg(AnsiColor::DarkGray)));
            }
            $content = Text::fromLines(...$lines);
        }

        if (is_string($content)) {
            $content = Text::fromString($content);
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Green))
            ->titles(Title::fromLine(Line::fromString(' Search Results ')->green()))
            ->widget(
                ParagraphWidget::fromText($content)
            );
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
