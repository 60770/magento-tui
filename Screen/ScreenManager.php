<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Widget;

class ScreenManager
{
    /** @var Screen[] */
    private array $screens = [];
    private string $currentScreen;

    public function __construct(TuiContext $context)
    {
        $this->screens = [
            'main' => new MainScreen(),
            'config' => new ConfigScreen(),
            'logs' => new LogsScreen(),
            'cache' => new CacheScreen(),
            'indexer' => new IndexScreen(),
            'deploy' => new DeployScreen(),
            'modules' => new ModuleScreen(),
            'urls' => new UrlScreen(),
            'database' => new DatabaseScreen(),
            'stats' => new StatsScreen(),
            'maintenance' => new MaintenanceScreen(),
        ];
        $this->currentScreen = 'main';
    }

    public function render(Area $area, TuiContext $context): Widget
    {
        return $this->screens[$this->currentScreen]->render($area, $context);
    }

    public function handleInput(string $key, TuiContext $context): ?string
    {
        $nextScreen = $this->screens[$this->currentScreen]->handleInput($key, $context);
        if ($nextScreen) {
            if ($nextScreen === 'quit') {
                return 'quit';
            }
            // Clear screen when changing screens
            if ($nextScreen !== $this->currentScreen) {
                echo "\033[2J\033[H";
                flush();
            }
            $this->currentScreen = $nextScreen;
        }
        return null;
    }

    public function currentScreenNeedsAutoRefresh(): bool
    {
        return $this->screens[$this->currentScreen]->needsAutoRefresh();
    }
}
