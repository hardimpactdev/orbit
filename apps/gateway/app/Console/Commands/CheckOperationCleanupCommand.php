<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationVersionCleanup;
use Illuminate\Console\Command;

final class CheckOperationCleanupCommand extends Command
{
    protected $signature = 'operations:check-cleanup {--fence-only : Close new starts before publication} {--json : Print the cleanup status as JSON}';

    protected $description = 'Fence deprecated Operation versions and check whether their admitted work has settled.';

    public function handle(OperationVersionCleanup $cleanup): int
    {
        if ($this->option('fence-only')) {
            $fence = $cleanup->fenceOnly();
            $this->line($this->option('json') ? json_encode($fence, JSON_THROW_ON_ERROR) : 'Deprecated Operation versions fenced.');

            return self::SUCCESS;
        }
        $result = $cleanup->check();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($result['versions'] as $version) {
                $this->line(sprintf('%s v%d: %d outstanding', $version['kind'], $version['version'], $version['outstanding']));
            }
            $this->line($result['eligible'] ? 'Eligible for cleanup.' : 'Cleanup deferred.');
        }

        return $result['eligible'] ? self::SUCCESS : 75;
    }
}
