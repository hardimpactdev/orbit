<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\HorizonManager;
use LaravelZero\Framework\Commands\Command;

final class HorizonLogsCommand extends Command
{
    protected $signature = 'horizon:logs {--lines=100 : Number of lines to show}';

    protected $description = 'Show Horizon logs';

    public function handle(HorizonManager $horizonManager): int
    {
        try {
            $lines = (int) $this->option('lines');
            $logs = $horizonManager->getLogs($lines);

            $this->line($logs);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
