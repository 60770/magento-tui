<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;

abstract class BaseScreen implements Screen
{
    protected int $selectedIndex = 0;

    public function handleInput(string $key, TuiContext $context): ?string
    {
        return match ($key) {
            "\e", "\033", 'q', 'Q' => 'main',
            "\033[A" => $this->moveSelection(-1),
            "\033[B" => $this->moveSelection(1),
            default => null,
        };
    }

    protected function moveSelection(int $delta): ?string
    {
        $max = $this->getMaxItems();
        if ($max > 0) {
            $this->selectedIndex = max(0, min($max - 1, $this->selectedIndex + $delta));
        }
        return null;
    }

    protected function getMaxItems(): int
    {
        return 0;
    }

    public function needsAutoRefresh(): bool
    {
        return false;
    }

    abstract public function render(Area $area, TuiContext $context): Widget;
}
