<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

/**
 * Starts agent conversations on the Node that owns the group's Instance.
 *
 * A no-op implementation returns null. A spawner implementation starts a fresh
 * implementer per subtask and a fresh reviewer for that subtask's first review,
 * then continues that reviewer on a later handoff of the same subtask.
 */
#[Bind(TaskAgentSpawner::class)]
interface AgentSpawner
{
    public function reserveReviewer(Task $task): ?int;

    public function spawnReviewer(Task $task): ?int;

    public function spawnImplementer(Task $task): ?int;

    public function requestReview(Task $task): void;
}
