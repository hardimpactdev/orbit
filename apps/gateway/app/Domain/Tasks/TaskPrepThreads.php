<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\Tasks\Pi\PiModel;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Str;

/** The agent threads of a prep group: one author and one reviewer for each review round. */
final readonly class TaskPrepThreads
{
    /**
     * The input of one turn of a prep run: the thread, the prompt and the workspace, with the SSH target of the task VM.
     *
     * @param  array<string,mixed>  $ssh
     * @return array<string,mixed>
     */
    public static function turn(AgentThread $thread, string $prompt, bool $new, Task $group, Task $subtask, Instance $instance, array $ssh): array
    {
        return [
            'new_session' => $new, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'subtask' => $subtask->id, 'thread_id' => $thread->id, 'checkout_path' => $instance->checkout_path,
            'session_id' => $thread->external_id, 'role' => $thread->role, 'prompt' => $prompt,
            'model' => $thread->model, 'effort' => $thread->effort, 'pi_host' => $instance->node->wireguard_ip,
            'pi_port' => app(PiConnection::class)->port(), 'candidate' => null,
            'deliverables' => $subtask->deliverables ?? [], 'ssh_execution' => $ssh,
        ];
    }

    public static function create(Task $group, Task $subtask, Instance $instance, string $role): AgentThread
    {
        $model = PiModel::onlyProvider(TaskAgentDefaults::vmModel($group, $role), 'orbit-task-vm');

        return AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $instance->node_id,
            'driver' => 'pi', 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => (string) Str::uuid(),
            'role' => $role, 'model' => $model, 'effort' => config('orbit.tasks.'.$role.'_effort', 'high'),
        ]);
    }
}
