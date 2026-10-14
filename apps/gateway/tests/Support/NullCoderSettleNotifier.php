<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Models\Task;

final readonly class NullCoderSettleNotifier implements CoderSettleNotifier
{
    public function notify(Task $group): void {}

    public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

    public function assistance(Task $group, string $reason): void {}
}
