<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
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
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;

class UrlScreen extends BaseScreen
{
    private array $stores = [];
    private ?TuiContext $context = null;
    private string $lastMessage = '';

    // Edit state
    private bool $isEditing = false;
    private int $editStep = 0; // 0: Base URL, 1: Secure Base URL, 2: Confirm
    private string $editInput = '';
    private array $editingStore = [];
    private string $newBaseUrl = '';
    private string $newBaseUrlSecure = '';

    protected function getMaxItems(): int
    {
        return count($this->stores);
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;
        $this->stores = $context->urlService->getAllStoreUrls();

        if ($this->isEditing) {
            return $this->renderEditModal();
        }

        $rows = array_map(function (array $store, int $index) {
            return TableRow::fromCells(
                TableCell::fromString($store['store_name'] ?? ''),
                TableCell::fromString($store['store_code'] ?? ''),
                TableCell::fromString($store['base_url'] ?? ''),
                TableCell::fromString($store['base_url_secure'] ?? '')
            );
        }, $this->stores, array_keys($this->stores));

        $header = TableRow::fromCells(
            TableCell::fromString('Store Name'),
            TableCell::fromString('Code'),
            TableCell::fromString('Base URL'),
            TableCell::fromString('Secure Base URL')
        );

        $tableState = new TableState(
            offset: 0,
            selected: $this->selectedIndex
        );

        $table = TableWidget::default()
            ->header($header)
            ->widths(
                Constraint::percentage(20),
                Constraint::percentage(15),
                Constraint::percentage(32),
                Constraint::percentage(33)
            )
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan))
            ->highlightSymbol('► ')
            ->state($tableState)
            ->rows(...$rows);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Store URL Management ')->yellow()))
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
            '[Enter] Edit store URLs',
            '[R] Refresh list',
        ];

        if (!empty($this->lastMessage)) {
            $actions[] = '→ ' . $this->lastMessage;
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

        if ($this->isEditing) {
            if ($key === "\x1b") { // ESC
                $this->isEditing = false;
                return null;
            }

            if ($key === "\n" || $key === "\r") {
                if ($this->editStep === 0) {
                    $this->newBaseUrl = $this->editInput ?: $this->editingStore['base_url'];
                    $this->editStep = 1;
                    $this->editInput = $this->editingStore['base_url_secure']; // Pre-fill next step
                } elseif ($this->editStep === 1) {
                    $this->newBaseUrlSecure = $this->editInput ?: $this->editingStore['base_url_secure'];
                    $this->editStep = 2;
                    $this->editInput = ''; // Clear input for confirmation step (not used but clean)
                } elseif ($this->editStep === 2) {
                    // Execute update
                    $result = $this->context->urlService->updateStoreUrls(
                        (int)$this->editingStore['store_id'],
                        $this->newBaseUrl,
                        $this->newBaseUrlSecure
                    );

                    if ($result['success']) {
                        $this->lastMessage = '✓ ' . $result['message'];
                        $this->stores = $this->context->urlService->getAllStoreUrls();
                    } else {
                        $this->lastMessage = '✗ ' . $result['message'];
                    }
                    $this->isEditing = false;
                }
                return null;
            }

            if ($this->editStep < 2) {
                if (ord($key) === 127) { // Backspace
                    $this->editInput = substr($this->editInput, 0, -1);
                } elseif (strlen($key) === 1 && ctype_print($key)) {
                    $this->editInput .= $key;
                }
            }
            return null;
        }

        $lowerKey = strtolower($key);

        // Refresh list
        if ($lowerKey === 'r') {
            $this->stores = $context->urlService->getAllStoreUrls();
            $this->lastMessage = 'Store list refreshed';
            return null;
        }

        // Edit URLs
        if (($key === "\n" || $key === "\r") && isset($this->stores[$this->selectedIndex])) {
            $this->startEditing($this->stores[$this->selectedIndex]);
            return null;
        }

        // Pass original key to parent for arrow key handling
        return parent::handleInput($key, $context);
    }

    private function startEditing(array $store): void
    {
        $this->isEditing = true;
        $this->editStep = 0;
        $this->editingStore = $store;
        $this->editInput = $store['base_url']; // Pre-fill with current value
        $this->newBaseUrl = '';
        $this->newBaseUrlSecure = '';
    }

    private function renderEditModal(): Widget
    {
        $title = match ($this->editStep) {
            0 => ' Edit Base URL ',
            1 => ' Edit Secure Base URL ',
            2 => ' Confirm Update ',
            default => ' Edit Store URLs '
        };

        $content = [];
        $content[] = Span::styled("Store: {$this->editingStore['store_name']} ({$this->editingStore['store_code']})", Style::default()->fg(AnsiColor::Cyan));
        $content[] = Span::fromString("");

        if ($this->editStep === 0) {
            $content[] = Span::fromString("Enter new Base URL:");
            $content[] = Span::styled($this->editInput . '█', Style::default()->fg(AnsiColor::Yellow));
            $content[] = Span::fromString("");
            $content[] = Span::fromString("Current: " . $this->editingStore['base_url']);
        } elseif ($this->editStep === 1) {
            $content[] = Span::fromString("Enter new Secure Base URL:");
            $content[] = Span::styled($this->editInput . '█', Style::default()->fg(AnsiColor::Yellow));
            $content[] = Span::fromString("");
            $content[] = Span::fromString("Current: " . $this->editingStore['base_url_secure']);
        } elseif ($this->editStep === 2) {
            $content[] = Span::fromString("New Base URL: " . $this->newBaseUrl);
            $content[] = Span::fromString("New Secure Base URL: " . $this->newBaseUrlSecure);
            $content[] = Span::fromString("");
            $content[] = Span::styled("Press [Enter] to confirm or [Esc] to cancel", Style::default()->fg(AnsiColor::Green));
        }

        // Convert spans to lines properly
        $lines = [];
        foreach ($content as $span) {
            $lines[] = Line::fromSpan($span);
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromLines(...$lines))
            );
    }
}
