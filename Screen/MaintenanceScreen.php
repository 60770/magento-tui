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

class MaintenanceScreen extends BaseScreen
{
    private bool $inputMode = false;
    private string $inputBuffer = '';
    private array $allowedIPs = [];

    protected function getMaxItems(): int
    {
        return count($this->allowedIPs);
    }
    
    public function render(Area $area, TuiContext $context): Widget
    {
        $status = $context->maintenanceService->getStatus();
        $this->allowedIPs = $status['allowed_ips'];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Double)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromLine(Line::fromString('Maintenance Mode Management')->yellow()))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::length(3),
                        Constraint::length(3),
                        Constraint::min(5),
                        Constraint::length(8)
                    )
                    ->widgets(
                        $this->renderMaintenanceStatus($status),
                        $this->renderCurrentIP($status['current_ip']),
                        $this->renderAllowedIPs($status['allowed_ips']),
                        $this->renderMaintenanceActions($status['current_ip'])
                    )
            );
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        if ($this->inputMode) {
            return $this->handleMaintenanceInput($key, $context);
        }

        switch (strtolower($key)) {
            case 't':
                $context->maintenanceService->toggle();
                break;
            case 'a':
                $this->inputMode = true;
                $this->inputBuffer = '';
                break;
            case 'd':
                if (isset($this->allowedIPs[$this->selectedIndex])) {
                    $context->maintenanceService->removeAllowedIP($this->allowedIPs[$this->selectedIndex]);
                    if ($this->selectedIndex > 0) {
                        $this->selectedIndex--;
                    }
                }
                break;
            case 'c':
                $context->maintenanceService->addAllowedIP($context->maintenanceService->getCurrentIP());
                break;
            case 'x':
                $context->maintenanceService->clearAllowedIPs();
                $this->selectedIndex = 0;
                break;
        }

        return parent::handleInput($key, $context);
    }

    private function handleMaintenanceInput(string $key, TuiContext $context): ?string
    {
        if ($key === "\e" || $key === "\033") {
            $this->inputMode = false;
            $this->inputBuffer = '';
            return null;
        }

        if ($key === "\n") {
            if (!empty($this->inputBuffer)) {
                $context->maintenanceService->addAllowedIP(trim($this->inputBuffer));
            }
            $this->inputMode = false;
            $this->inputBuffer = '';
            return null;
        }

        if ($key === "\x7f" || $key === "\x08") {
            if (strlen($this->inputBuffer) > 0) {
                $this->inputBuffer = substr($this->inputBuffer, 0, -1);
            }
            return null;
        }

        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
            if (strlen($this->inputBuffer) < 45) { // Max IP length with CIDR
                $this->inputBuffer .= $key;
            }
        }
        
        return null;
    }

    private function renderMaintenanceStatus(array $status): Widget
    {
        $statusText = $status['enabled'] ? 'MAINTENANCE MODE ENABLED' : 'MAINTENANCE MODE DISABLED';
        $style = $status['enabled']
            ? Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)
            : Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString('Current Status')->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($statusText))->alignment(HorizontalAlignment::Center)->style($style)
            );
    }

    private function renderCurrentIP(string $ip): Widget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString('Your Current IP Address')->yellow()))
            ->widget(
                ParagraphWidget::fromText(Text::fromString($ip))->alignment(HorizontalAlignment::Center)
            );
    }

    private function renderAllowedIPs(array $ips): Widget
    {
        $items = array_map(function (string $ip, int $index) {
            $style = $this->selectedIndex === $index
                ? Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)
                : Style::default()->fg(AnsiColor::White);

            return ListItem::fromString($ip)->style($style);
        }, $ips, array_keys($ips));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString('Allowed IP Addresses')->yellow()))
            ->widget(
                ListWidget::default()
                    ->items(...$items)
                    ->highlightSymbol('>> ')
            );
    }

    private function renderMaintenanceActions(string $currentIP): Widget
    {
        $actions = [
            '[T] Toggle Maintenance Mode',
            '[A] Add new IP address',
            '[D] Delete selected IP address',
            '[C] Add current IP (' . $currentIP . ')',
            '[X] Clear all IP addresses',
            '[ESC] Back to main menu',
        ];

        $items = array_map(fn(string $action) => ListItem::fromString($action), $actions);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromLine(Line::fromString('Actions')->yellow()))
            ->widget(
                ListWidget::default()->items(...$items)
            );
    }
}
