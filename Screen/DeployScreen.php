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
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Style\Modifier;

class DeployScreen extends BaseScreen
{
    private array $operations = [];
    private ?TuiContext $context = null;

    protected function getMaxItems(): int
    {
        return count($this->operations);
    }

    public function needsAutoRefresh(): bool
    {
        return $this->context !== null && $this->context->deployService->isExecuting();
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;

        try {
            $this->operations = $context->deployService->getAvailableOperations();
        } catch (\Exception $e) {
            $this->operations = [];
        }

        try {
            $status = $context->deployService->getDeploymentStatus();
        } catch (\Exception $e) {
            $status = [
                'mode' => 'unknown',
                'static_content_deployed' => false,
                'di_compiled' => false,
                'maintenance_enabled' => false,
            ];
        }

        $isExecuting = $context->deployService->isExecuting();
        $output = $context->deployService->getOutput();

        $widgets = [
            $this->renderStatus($status),
            $this->renderOperations(),
        ];

        $constraints = [
            Constraint::length(8),
            Constraint::min(10),
        ];

        if ($isExecuting || !empty($output)) {
            $widgets[] = $this->renderProgress($output, $isExecuting);
            $constraints[] = Constraint::length(12);
        }

        $widgets[] = $this->renderActions($isExecuting);
        $constraints[] = Constraint::length(8);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Deployment Management ')->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(...$constraints)
                    ->widgets(...$widgets)
            );
    }


    private function renderStatus(array $status): Widget
    {
        $modeColor = match ($status['mode']) {
            'developer' => AnsiColor::Yellow,
            'production' => AnsiColor::Green,
            default => AnsiColor::White
        };

        $statusText = sprintf(
            "Mode: %s\nStatic Content: %s\nDI Compiled: %s\nMaintenance: %s",
            strtoupper($status['mode']),
            $status['static_content_deployed'] ? 'Deployed' : 'Not Deployed',
            $status['di_compiled'] ? 'Yes' : 'No',
            $status['maintenance_enabled'] ? 'Enabled' : 'Disabled'
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' System Status ')->cyan()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($statusText))
                    ->style(Style::default()->fg(AnsiColor::White))
            );
    }

    private function renderOperations(): Widget
    {
        if (empty($this->operations)) {
            $items = [ListItem::fromString('No operations available')];
        } else {
            $items = array_map(function (array $op, int $index) {
                $style = $index === $this->selectedIndex
                    ? Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)
                    : Style::default()->fg(AnsiColor::White);

                $symbol = $index === $this->selectedIndex ? '► ' : '  ';

                $title = $op['title'] ?? 'Unknown';
                $description = $op['description'] ?? '';
                return ListItem::fromString(
                    sprintf('%s%s - %s', $symbol, $title, $description)
                );
            }, $this->operations, array_keys($this->operations));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' Available Operations ')->yellow()))
            ->widget(
                ListWidget::default()->items(...$items)
            );
    }

    private function renderProgress(string $output, bool $isRunning): Widget
    {
        $titleText = $isRunning ? ' Execution in Progress... ⏳ ' : ' Last Operation Output ';
        $titleColor = $isRunning ? AnsiColor::Yellow : AnsiColor::Green;

        // Get last 8 lines of output
        $lines = explode("\n", $output);
        $lines = array_slice($lines, -8);
        $displayText = implode("\n", $lines);

        if (empty($displayText)) {
            $displayText = $isRunning ? 'Starting process...' : 'No output';
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

    private function renderActions(bool $isExecuting): Widget
    {
        if ($isExecuting) {
            $actions = [
                '⏳ Executing... Please wait...',
                '[C] Clear output (stop monitoring)',
                '[ESC/q] Back to main menu',
            ];
        } else {
            $actions = [
                '[Enter] Execute selected operation',
                '[M] Toggle maintenance mode',
                '[C] Clear output',
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

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;
        $this->operations = $context->deployService->getAvailableOperations();

        $lowerKey = strtolower($key);

        // Handle clear output
        if ($lowerKey === 'c') {
            $context->deployService->clearOutput();
            return null;
        }

        // Prevent other inputs during execution
        if ($context->deployService->isExecuting()) {
            return parent::handleInput($key, $context);
        }

        if (($key === "\n" || $key === "\r") && isset($this->operations[$this->selectedIndex])) {
            $operation = $this->operations[$this->selectedIndex];
            $context->deployService->executeCommand($operation['command']);
            return null;
        }

        if ($lowerKey === 'm') {
            try {
                if ($context->maintenanceService->isEnabled()) {
                    $context->maintenanceService->disable();
                } else {
                    $context->maintenanceService->enable();
                }
            } catch (\Exception $e) {
                // Error handling
            }
            return null;
        }

        // Pass original key to parent for arrow key handling
        return parent::handleInput($key, $context);
    }
}
