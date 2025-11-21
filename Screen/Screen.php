<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Display\Area;

interface Screen
{
    public function render(Area $area, TuiContext $context): Widget;

    public function handleInput(string $key, TuiContext $context): ?string;

    /**
     * Check if this screen needs auto-refresh (for live data screens)
     * @return bool
     */
    public function needsAutoRefresh(): bool;
}
