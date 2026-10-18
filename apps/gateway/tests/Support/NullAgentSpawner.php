<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\AgentSpawner;
use App\Models\Task;

final readonly class NullAgentSpawner implements AgentSpawner
{
    public function reserveReviewer(Task $task): ?int
    {
        return null;
    }

    public function spawnReviewer(Task $task): ?int
    {
        return null;
    }

    public function spawnImplementer(Task $task): ?int
    {
        return null;
    }

    public function requestReview(Task $task): void {}
}
