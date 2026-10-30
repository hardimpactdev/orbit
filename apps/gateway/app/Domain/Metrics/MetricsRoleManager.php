<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsMutationData;
use App\Models\Operation;

interface MetricsRoleManager
{
    public function enable(int $nodeId): MetricsMutationData;

    public function remove(bool $force, bool $purge): MetricsMutationData;

    public function enableExporter(int $nodeId): Operation;

    public function disableExporter(int $nodeId): Operation;
}
