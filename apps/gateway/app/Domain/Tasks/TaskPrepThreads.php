<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\Pi\PiModel;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Str;

/** The agent threads of a prep group: one author and one reviewer for each review round. */
final readonly class TaskPrepThreads
{
    public static function create(Task $group, Task $subtask, Instance $instance, string $role): AgentThread
    {
        $model = PiModel::onlyProvider($role === 'implementer' ? $group->implementer_model : $group->reviewer_model, 'orbit-task-vm');

        return AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $instance->node_id,
            'driver' => 'pi', 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => (string) Str::uuid(),
            'role' => $role, 'model' => $model, 'effort' => config('orbit.tasks.'.$role.'_effort', 'high'),
        ]);
    }
}
