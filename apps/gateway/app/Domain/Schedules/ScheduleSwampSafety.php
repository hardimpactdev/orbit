<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class ScheduleSwampSafety
{
    public function __construct(private ProcessSwampSafety $executors) {}

    public function requiresSynchronous(Node $node): bool
    {
        return $this->executors->requiresSynchronous($node);
    }

    public function assertAllowed(Node $node): void
    {
        if ($this->requiresSynchronous($node)) {
            throw new ResourceOperationException('schedule.executor_dependency', 'Schedules on Swamp executor Nodes require synchronous control.', 409);
        }
    }
}
