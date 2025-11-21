<?php
declare(strict_types=1);

namespace Tidycode\TUI\Screen;

use Tidycode\TUI\Service\CacheService;
use Tidycode\TUI\Service\ConfigurationService;
use Tidycode\TUI\Service\LogService;
use Tidycode\TUI\Service\MaintenanceService;
use Tidycode\TUI\Service\OrderStatsService;
use Tidycode\TUI\Service\StatisticsService;
use Tidycode\TUI\Service\IndexService;
use Tidycode\TUI\Service\DeployService;
use Tidycode\TUI\Service\ModuleService;
use Tidycode\TUI\Service\UrlService;
use Tidycode\TUI\Service\DatabaseService;

class TuiContext
{
    public function __construct(
        public readonly ConfigurationService $configService,
        public readonly LogService $logService,
        public readonly CacheService $cacheService,
        public readonly StatisticsService $statsService,
        public readonly OrderStatsService $orderStatsService,
        public readonly MaintenanceService $maintenanceService,
        public readonly IndexService $indexService,
        public readonly DeployService $deployService,
        public readonly ModuleService $moduleService,
        public readonly UrlService $urlService,
        public readonly DatabaseService $databaseService
    ) {
    }
}
