<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;
use App\Models\Process;

/** Projects an owning record after native installation; never executes runtime work. */
interface ProcessInstallationProjection
{
    public function complete(Operation $operation, Process $process, OperationOutcome $outcome): void;
}
