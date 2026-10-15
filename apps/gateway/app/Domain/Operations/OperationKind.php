<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/**
 * The work of one operation kind, such as `instance.deploy`. The runner calls `run()` on every attempt; the kind plans
 * its steps through the run and runs each one through it, so a resumed attempt skips the steps that already
 * succeeded. When the runner fails an operation outside `run()`, such as at a step that a lost worker interrupted, it
 * calls `abandon()` so the kind can finish its own records.
 */
interface OperationKind
{
    public function run(Operation $operation, OperationRun $run): OperationOutcome;

    /** Finishes the kind's records for an operation that ends with this failure, and returns the outcome to store. */
    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome;
}
