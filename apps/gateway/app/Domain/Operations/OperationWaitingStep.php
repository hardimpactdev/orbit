<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** Reads existing work, or confirms its stop. A read may call OperationRun::wait() again. */
interface OperationWaitingStep
{
    public function read(OperationRun $run): mixed;

    /** Throws the step's error when the stop cannot be confirmed. */
    public function stop(OperationRun $run): void;
}
