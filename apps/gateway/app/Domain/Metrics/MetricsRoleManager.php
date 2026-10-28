<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Operation;

interface MetricsRoleManager
{
    public function enable(int $nodeId): Operation;

    public function remove(bool $force, bool $purge): Operation;

    public function enableExporter(int $nodeId): Operation;

    public function disableExporter(int $nodeId): Operation;
}
