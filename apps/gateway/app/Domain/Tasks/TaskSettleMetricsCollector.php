<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

#[Bind(LocalTaskSettleMetricsCollector::class)]
interface TaskSettleMetricsCollector
{
    public function collect(Task $group): TaskSettleMetrics;
}
