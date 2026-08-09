<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\OperationRun;
use App\Models\UpdateLease;
use App\Services\Operations\UpdateLeaseHeartbeatProcess;

final class InlineUpdateLeaseHeartbeatProcess extends UpdateLeaseHeartbeatProcess
{
    #[\Override]
    public function whileRunning(
        OperationRun $operationRun,
        UpdateLease $fleetLease,
        int $ttlSeconds,
        callable $callback,
    ): mixed {
        return $callback();
    }
}
