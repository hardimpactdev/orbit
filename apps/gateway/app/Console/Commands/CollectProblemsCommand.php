<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Problems\ProblemCollector;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CollectProblemsCommand extends Command
{
    #[\Override]
    protected $signature = 'problems:collect {--horizon : Inspect only the Gateway Horizon Process, without a fleet scan}';

    #[\Override]
    protected $description = 'Record recurring Doctor, Activity, log, and assistance problems.';

    public function handle(ProblemCollector $collector, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $horizon = (bool) $this->option('horizon');
        $lock = $horizon
            ? Cache::lock('orbit:problems:collect:horizon', 60)
            : Cache::lock('orbit:problems:collect', 15 * 60);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            if ($horizon) {
                $collector->collectHorizon();
                $failures = [];
            } else {
                $failures = $collector->collect();
            }
        } finally {
            $lock->release();
        }

        foreach ($failures as $exception) {
            $this->reportOnce($exception);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function reportOnce(Throwable $exception): void
    {
        $key = 'problems:collect:reported:'.hash('sha256', $exception::class);

        if (Cache::add($key, true, now()->addHour())) {
            report($exception);
        }
    }
}
