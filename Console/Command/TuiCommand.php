<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Display\Display;
use PhpTui\Term\Terminal;
use Tidycode\TUI\Screen\ScreenManager;
use Tidycode\TUI\Screen\TuiContext;
use Tidycode\TUI\Service\ConfigurationService;
use Tidycode\TUI\Service\LogService;
use Tidycode\TUI\Service\CacheService;
use Tidycode\TUI\Service\StatisticsService;
use Tidycode\TUI\Service\OrderStatsService;
use Tidycode\TUI\Service\MaintenanceService;
use Tidycode\TUI\Service\IndexService;
use Tidycode\TUI\Service\DeployService;
use Tidycode\TUI\Service\ModuleService;
use Tidycode\TUI\Service\UrlService;
use Tidycode\TUI\Service\DatabaseService;

/**
 * Full-Screen TUI Command using php-tui
 */
class TuiCommand extends Command
{
    private ?Display $display = null;
    private TuiContext $context;
    private ScreenManager $screenManager;

    /**
     * @param ConfigurationService $configService
     * @param LogService $logService
     * @param CacheService $cacheService
     * @param StatisticsService $statsService
     * @param OrderStatsService $orderStatsService
     * @param MaintenanceService $maintenanceService
     * @param IndexService $indexService
     * @param DeployService $deployService
     * @param ModuleService $moduleService
     * @param UrlService $urlService
     * @param DatabaseService $databaseService
     * @param string|null $name
     */
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly LogService $logService,
        private readonly CacheService $cacheService,
        private readonly StatisticsService $statsService,
        private readonly OrderStatsService $orderStatsService,
        private readonly MaintenanceService $maintenanceService,
        private readonly IndexService $indexService,
        private readonly DeployService $deployService,
        private readonly ModuleService $moduleService,
        private readonly UrlService $urlService,
        private readonly DatabaseService $databaseService,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Configure command
     */
    protected function configure(): void
    {
        $this->setName('tidycode:tui')
            ->setDescription('Launch Full-Screen Terminal User Interface for Magento 2 Backend Management');

        parent::configure();
    }

    /**
     * Execute command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->setupTerminal();
            $this->display = DisplayBuilder::default()->build();
            $this->context = new TuiContext(
                $this->configService,
                $this->logService,
                $this->cacheService,
                $this->statsService,
                $this->orderStatsService,
                $this->maintenanceService,
                $this->indexService,
                $this->deployService,
                $this->moduleService,
                $this->urlService,
                $this->databaseService
            );
            $this->screenManager = new ScreenManager($this->context);

            $running = true;
            $needsRedraw = true;
            $lastAutoRefresh = microtime(true);
            $autoRefreshInterval = 1.0; // Check for auto-refresh every 1 second

            while ($running) {
                // Only render when needed
                if ($needsRedraw) {
                    $widget = $this->screenManager->render($this->display->viewportArea(), $this->context);
                    $this->display->draw($widget);
                    $needsRedraw = false;
                }

                $key = $this->readKey();

                // Only process and redraw if we got input
                if ($key !== '') {
                    $action = $this->screenManager->handleInput($key, $this->context);

                    if ($action === 'quit') {
                        $running = false;
                    } else {
                        $needsRedraw = true;
                    }
                } else {
                    // Check if current screen needs auto-refresh
                    $currentTime = microtime(true);
                    if ($this->screenManager->currentScreenNeedsAutoRefresh()
                        && ($currentTime - $lastAutoRefresh >= $autoRefreshInterval)) {
                        $needsRedraw = true;
                        $lastAutoRefresh = $currentTime;
                    }

                    // No input, sleep briefly to reduce CPU usage
                    usleep(50000); // 50ms
                }
            }
        } catch (\Exception $e) {
            $this->cleanupTerminal();
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        } finally {
            $this->cleanupTerminal();
        }

        return Command::SUCCESS;
    }

    /**
     * Setup terminal for TUI mode
     */
    private function setupTerminal(): void
    {
        system('clear');
        echo "\033[?25l"; // Hide cursor
        system('stty -icanon -echo'); // Set raw mode: no canonical input, no echo
        flush();
    }

    /**
     * Cleanup terminal and restore normal mode
     */
    private function cleanupTerminal(): void
    {
        // Show cursor
        echo "\033[?25h";

        // Flush output
        flush();

        // Clear display if available
        if ($this->display !== null) {
            $this->display->clear();
        }

        // Clear screen
        system('clear');

        // Reset terminal settings
        system('stty sane');
    }

    /**
     * Read keyboard input and mouse events
     */
    private function readKey(): string
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        // Read with short timeout for responsiveness
        if (stream_select($read, $write, $except, 0, 50000) > 0) {
            // Read first byte to detect sequence type
            $first = fread(STDIN, 1);

            if ($first === "\033") {
                // Check if there's more data available immediately
                $read2 = [STDIN];
                $write2 = null;
                $except2 = null;

                if (stream_select($read2, $write2, $except2, 0, 50000) > 0) {
                    $second = fread(STDIN, 1);

                    if ($second === '[') {
                        // Wait for third byte
                        $read3 = [STDIN];
                        $write3 = null;
                        $except3 = null;

                        if (stream_select($read3, $write3, $except3, 0, 50000) > 0) {
                            $third = fread(STDIN, 1);

                            if ($third === '<') {
                                // SGR mouse sequence - consume and ignore
                                while (stream_select($read, $write, $except, 0, 10000) > 0) {
                                    $char = fread(STDIN, 1);
                                    if ($char === 'M' || $char === 'm') {
                                        break;
                                    }
                                }
                                return ''; // Ignore mouse events
                            } elseif ($third === '5' || $third === '6' || $third === '1' || $third === '2' || $third === '3' || $third === '4') {
                                // Multi-character sequences like Page Up (5~), Page Down (6~), Home (1~), End (4~), etc.
                                // Read all remaining characters until we find the terminator
                                $sequence = $third;
                                $maxChars = 5; // Safety limit
                                $charCount = 0;
                                
                                while ($charCount < $maxChars) {
                                    $readN = [STDIN];
                                    $writeN = null;
                                    $exceptN = null;
                                    
                                    if (stream_select($readN, $writeN, $exceptN, 0, 50000) > 0) {
                                        $char = fread(STDIN, 1);
                                        $sequence .= $char;
                                        $charCount++;
                                        
                                        // Check if we found the terminator
                                        if ($char === '~' || $char === ';' || ctype_alpha($char)) {
                                            break;
                                        }
                                    } else {
                                        break;
                                    }
                                }
                                
                                return "\033[" . $sequence;
                            } else {
                                // Arrow key or other escape sequence
                                return "\033[" . $third;
                            }
                        }
                    } else {
                        return "\033" . $second;
                    }
                } else {
                    // Just ESC key
                    return "\033";
                }
            } else {
                return $first;
            }
        }

        return '';
    }
}
