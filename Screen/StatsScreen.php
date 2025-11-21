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

class StatsScreen extends BaseScreen
{
    public function render(Area $area, TuiContext $context): Widget
    {
        $stats = $context->statsService->getAllStatistics();

        $systemRows = [];
        foreach ($stats['system'] as $key => $value) {
            $systemRows[] = TableRow::fromCells(
                TableCell::fromString(str_replace('_', ' ', ucwords($key, '_'))),
                TableCell::fromString((string)$value)
            );
        }

        $dbRows = [];
        foreach ($stats['database'] as $key => $value) {
            $dbRows[] = TableRow::fromCells(
                TableCell::fromString(str_replace('_', ' ', ucwords($key, '_'))),
                TableCell::fromString((string)$value)
            );
        }

        $moduleRows = [
            TableRow::fromCells(
                TableCell::fromString('Total Modules'),
                TableCell::fromString((string)$stats['modules']['total_modules'])
            ),
            TableRow::fromCells(
                TableCell::fromString('Enabled Modules'),
                TableCell::fromString((string)$stats['modules']['enabled_modules'])
            ),
        ];

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(count($systemRows) + 2),
                Constraint::length(count($dbRows) + 2),
                Constraint::length(count($moduleRows) + 2),
                Constraint::min(0)
            )
            ->widgets(
                $this->createStatsTable('System Information', $systemRows),
                $this->createStatsTable('Database Statistics', $dbRows),
                $this->createStatsTable('Module Statistics', $moduleRows),
                BlockWidget::default()
            );
    }

    private function createStatsTable(string $title, array $rows): Widget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString($title)->yellow()))
            ->widget(
                TableWidget::default()
                    ->widths(Constraint::percentage(50), Constraint::percentage(50))
                    ->rows(...$rows)
            );
    }
}
