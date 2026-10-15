<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** One step an operation kind plans before it runs: its unique name and whether it may run again after a lost worker. */
final readonly class OperationStepPlan
{
    public function __construct(
        public string $name,
        public bool $resumable,
    ) {}
}
