<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsMutationData;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeMetricsRoleManager::class)]
interface MetricsRoleManager
{
    public function enable(int $nodeId): MetricsMutationData;

    public function remove(bool $force, bool $purge): MetricsMutationData;

    public function enableExporter(int $nodeId): MetricsMutationData;

    public function disableExporter(int $nodeId): MetricsMutationData;
}
