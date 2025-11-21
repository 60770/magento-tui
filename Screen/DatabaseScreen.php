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
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Text\Span;

class DatabaseScreen extends BaseScreen
{
    private ?TuiContext $context = null;
    private string $lastMessage = '';
    private bool $confirmDump = false;
    private bool $confirmRestore = false;
    private int $selectedBackupIndex = -1;

    protected function getMaxItems(): int
    {
        return 0;
    }

    public function needsAutoRefresh(): bool
    {
        return $this->context !== null && $this->context->databaseService->isProcessing();
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        $this->context = $context;

        if ($context->databaseService->isProcessing()) {
            return $this->renderProgress($context);
        }

        if ($this->confirmDump) {
            return $this->renderDumpConfirmation();
        }

        if ($this->confirmRestore) {
            return $this->renderRestoreConfirmation();
        }

        return $this->renderMainMenu($context);
    }

    private function renderMainMenu(TuiContext $context): Widget
    {
        $dbInfo = $context->databaseService->getDatabaseInfo();
        $backups = $context->databaseService->listBackups();

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString(' Database Management ')->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::length(7),
                        Constraint::length(8),
                        Constraint::min(5),
                        Constraint::length(6)
                    )
                    ->widgets(
                        $this->renderDbInfo($dbInfo),
                        $this->renderOperations(),
                        $this->renderBackupList($backups),
                        $this->renderActions()
                    )
            );
    }

    private function renderDbInfo(array $dbInfo): Widget
    {
        $items = [
            ListItem::fromString(sprintf('Database: %s', $dbInfo['dbname'] ?? 'N/A')),
            ListItem::fromString(sprintf('Host: %s', $dbInfo['host'] ?? 'N/A')),
            ListItem::fromString(sprintf('User: %s', $dbInfo['username'] ?? 'N/A')),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' Database Info ')->cyan()))
            ->widget(ListWidget::default()->items(...$items));
    }

    private function renderOperations(): Widget
    {
        $items = [
            ListItem::fromString('[D] Create new database dump'),
            ListItem::fromString('[R] Restore from backup (select from list below)'),
            ListItem::fromString('[L] Refresh backup list'),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Operations ')->yellow()))
            ->widget(ListWidget::default()->items(...$items));
    }

    private function renderBackupList(array $backups): Widget
    {
        if (empty($backups)) {
            $items = [ListItem::fromString('No backups found in var/backups')];
        } else {
            $items = array_map(function($backup) {
                $size = $this->formatBytes($backup['size']);
                $date = date('Y-m-d H:i:s', $backup['date']);
                return ListItem::fromString(
                    sprintf('%s - %s (%s)', $backup['filename'], $date, $size)
                );
            }, $backups);
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString(' Available Backups ')->green()))
            ->widget(ListWidget::default()->items(...$items));
    }

    private function renderDumpConfirmation(): Widget
    {
        $filename = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
        $backupPath = BP . '/var/backups/' . $filename;

        $confirmText = sprintf(
            "Create Database Dump?\n\nBackup will be saved to:\n%s\n\n⚠️  WARNING:\n- This operation may take several minutes\n- Tables will be locked during the dump\n\nPress [Y] to confirm or [N] to cancel",
            $backupPath
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Confirm Database Dump ')->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($confirmText))
                    ->style(Style::default()->fg(AnsiColor::White))
            );
    }

    private function renderRestoreConfirmation(): Widget
    {
        $backups = $this->context->databaseService->listBackups();
        
        if ($this->selectedBackupIndex < 0 || $this->selectedBackupIndex >= count($backups)) {
            $this->confirmRestore = false;
            $this->selectedBackupIndex = -1;
            return $this->renderMainMenu($this->context);
        }

        $backup = $backups[$this->selectedBackupIndex];
        $dbName = $this->context->databaseService->getDatabaseInfo()['dbname'] ?? 'unknown';

        $confirmText = sprintf(
            "Restore Database from Backup?\n\n⚠️  WARNING: This will REPLACE the current database!\n\nDatabase: %s\nBackup: %s\nSize: %s\nDate: %s\n\nPress [Y] to confirm or [N] to cancel",
            $dbName,
            $backup['filename'],
            $this->formatBytes($backup['size']),
            date('Y-m-d H:i:s', $backup['date'])
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Red))
            ->titles(Title::fromLine(Line::fromString(' ⚠️  Confirm Database Restore ')->red()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($confirmText))
                    ->style(Style::default()->fg(AnsiColor::White))
            );
    }

    private function renderActions(): Widget
    {
        $actions = [];

        if (!empty($this->lastMessage)) {
            $actions[] = '→ ' . $this->lastMessage;
        }

        $actions[] = '[ESC/q] Back to main menu';

        $items = array_map(fn(string $action) => ListItem::fromString($action), $actions);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString(' Status ')->yellow()))
            ->widget(ListWidget::default()->items(...$items));
    }

    private function renderProgress(TuiContext $context): Widget
    {
        $operation = $context->databaseService->getCurrentOperation();
        $output = $context->databaseService->getOutput();

        $lines = explode("\n", $output);
        $lines = array_slice($lines, -10);
        $outputText = implode("\n", $lines);

        if (empty($outputText)) {
            $outputText = ucfirst($operation) . ' in progress...';
        }

        $title = match($operation) {
            'dump' => ' Creating Database Dump... ',
            'restore' => ' Restoring Database... ',
            default => ' Processing... '
        };

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->titles(Title::fromLine(Line::fromString($title . '⏳')->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::min(10),
                        Constraint::length(6)
                    )
                    ->widgets(
                        BlockWidget::default()
                            ->borders(Borders::ALL)
                            ->borderType(BorderType::Rounded)
                            ->titles(Title::fromLine(Line::fromString(' Progress ')->cyan()))
                            ->widget(
                                ParagraphWidget::fromText(Text::fromString($outputText))
                                    ->style(Style::default()->fg(AnsiColor::White))
                            ),
                        BlockWidget::default()
                            ->borders(Borders::ALL)
                            ->borderType(BorderType::Rounded)
                            ->titles(Title::fromLine(Line::fromString(' Info ')->yellow()))
                            ->widget(
                                ListWidget::default()->items(
                                    ListItem::fromString('Operation: ' . ucfirst($operation)),
                                    ListItem::fromString('This may take several minutes...'),
                                    ListItem::fromString('Please wait for completion')
                                )
                            )
                    )
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $this->context = $context;

        if ($context->databaseService->isProcessing()) {
            return null;
        }

        $lowerKey = strtolower($key);

        // Handle Dump Confirmation
        if ($this->confirmDump) {
            if ($lowerKey === 'y') {
                $filename = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
                $backupPath = BP . '/var/backups/' . $filename;
                $this->context->databaseService->createDump($backupPath, true);
                $this->lastMessage = "✓ Dump started: $filename";
                $this->confirmDump = false;
            } elseif ($lowerKey === 'n' || $key === 'Esc') {
                $this->confirmDump = false;
                $this->lastMessage = 'Dump cancelled';
            }
            return null;
        }

        // Handle Restore Confirmation
        if ($this->confirmRestore) {
            $backups = $this->context->databaseService->listBackups();
            
            if ($lowerKey === 'y') {
                if (isset($backups[$this->selectedBackupIndex])) {
                    $backup = $backups[$this->selectedBackupIndex];
                    $this->context->databaseService->restoreDump($backup['path']);
                    $this->lastMessage = "✓ Restore started: {$backup['filename']}";
                }
                $this->confirmRestore = false;
                $this->selectedBackupIndex = -1;
            } elseif ($lowerKey === 'n' || $key === 'Esc') {
                $this->confirmRestore = false;
                $this->selectedBackupIndex = -1;
                $this->lastMessage = 'Restore cancelled';
            } elseif ($key === 'Up') {
                 $this->selectedBackupIndex = max(0, $this->selectedBackupIndex - 1);
            } elseif ($key === 'Down') {
                 $this->selectedBackupIndex = min(count($backups) - 1, $this->selectedBackupIndex + 1);
            }
            return null;
        }

        // Normal Mode
        if ($lowerKey === 'd') {
            $this->confirmDump = true;
            return null;
        }

        if ($lowerKey === 'r') {
            $backups = $this->context->databaseService->listBackups();
            if (empty($backups)) {
                $this->lastMessage = '✗ No backups available';
            } else {
                $this->confirmRestore = true;
                $this->selectedBackupIndex = 0;
            }
            return null;
        }

        if ($lowerKey === 'l') {
            $this->lastMessage = 'Backup list refreshed';
            return null;
        }

        return parent::handleInput($key, $context);
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
