<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Str;

/**
 * The admission of one agent turn in a task copy (docs/cli/task/turn.mmd). Swamp runs T3's own `t3 thread` command in the copy as
 * `agent`; T3 issues and revokes its session inside the container, so the intent names no credential and no vault key.
 */
final readonly class TaskCopyTurn
{
    /** How long the agent's turn may run before Swamp ends its wait; the bound of a task VM turn. */
    public const int TurnSeconds = 3600;

    public function __construct(private TaskOperations $operations, private OperationIdempotency $idempotency, private TaskCheckedCandidate $candidates) {}

    public function start(Task $group, TaskTurnData $data, string $caller, ?string $request): Operation
    {
        $start = fn (): Operation => $this->operations->start($group, OperationKinds::TasksCopyTurn, $data->toArray(), static function (Task $group): void {},
            $caller, $request, fn (Task $group): array => $this->capture($group, $data));
        $key = $this->idempotency->key;
        $fingerprint = $this->idempotency->fingerprint;

        // The request carries the key of `tasks.turn`; the turn of a copy is its own kind, so the key goes with it.
        return $this->idempotency->kind === OperationKinds::TasksTurn && $key !== null && $fingerprint !== null
            ? $this->idempotency->under(OperationKinds::TasksCopyTurn, $key, $fingerprint, $start)
            : $start();
    }

    /** @return array<string,mixed> */
    public function capture(Task $group, TaskTurnData $data): array
    {
        $group->load('taskable', 'project');
        $workspace = TaskInstanceWorkspace::forGroup($group)
            ?? throw new ResourceOperationException('tasks.no_workspace', 'The group requires its task copy.', 409);
        if ($workspace->instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('tasks.copy_not_ready', 'The task copy is not active yet.', 409);
        }
        if ($data->role === 'consult' || $data->model !== null) {
            throw new ResourceOperationException('tasks.copy_turn_unsupported', 'A turn in a task copy is an implementer or reviewer turn with its role\'s model.', 422);
        }
        self::refuseUnended($group);
        $task = $this->operations->requireCurrentSubtask($group, $data->subtask);
        $role = $data->threadRole();
        $instance = $workspace->instance;
        $runtime = 'instance:'.$instance->id;
        $candidate = null;
        if ($role === TaskThreadRole::Reviewer) {
            $check = TaskCheck::query()->where('task_id', $task->id)->find($data->check);
            if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
                throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires the current subtask checked candidate.', 422);
            }
            $candidate = $check->commit_sha;
        }
        // A continued turn sends to the role's T3 thread in this copy; T3 finds it by the title that names the Orbit thread.
        $thread = $data->thread === 'new' ? null : AgentThread::query()->where('task_group_id', $group->id)->find((int) $data->thread);
        if ($data->thread !== 'new' && (! $thread instanceof AgentThread || $thread->role !== $role->value || $thread->driver !== 't3' || $thread->runtime_key !== $runtime)) {
            throw new ResourceOperationException('tasks.thread_role_mismatch', 'The thread must be a thread of this role in this task copy.', 422);
        }
        $provider = null;
        if ($thread === null) {
            [$provider, $model] = self::model($role);
            $thread = AgentThread::query()->create([
                'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
                'driver' => 't3', 'runtime_key' => $runtime, 'external_id' => (string) Str::uuid(),
                'role' => $role->value, 'model' => $model, 'effort' => null,
            ]);
        }
        ($role === TaskThreadRole::Implementer ? $task : $group)->update([$role->value.'_agent_thread_id' => $thread->id]);
        app(TaskBroadcasts::class)->threadChanged($thread->id);
        $target = $workspace->intent();

        return [
            ...$data->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'runtime' => $target['runtime'], 'branch' => $target['branch'], 'thread_id' => $thread->id, 'candidate' => $candidate,
            'turn' => ['t3' => InstanceContainer::T3, 'thread' => $provider === null ? 'continue' : 'new', 'provider' => $provider, 'model' => $thread->model,
                'seconds' => self::TurnSeconds, 'title' => 'Orbit task '.$group->id.' thread '.$thread->id, 'prompt' => TaskTurnPrompt::compose($task, $data->prompt)],
        ];
    }

    /**
     * Refuses more work on a group while an earlier turn may still run in its copy. T3's CLI cannot stop a run, so a turn whose run
     * started and whose result does not show T3's run over (its bound cut the wait, a cancel or a lost phase) leaves the agent writing
     * in the checkout. Only `task:cancel` ends it, with the copy. Called by the turn and by the check of a copy.
     */
    public static function refuseUnended(Task $group): void
    {
        $unended = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', OperationKinds::TasksCopyTurn)->whereIn('status', OperationStatus::terminalValues())->orderBy('id')->get()
            ->first(static fn (Operation $turn): bool => ($turn->state['swamp_run_id'] ?? null) !== null && ($turn->result['ended'] ?? null) !== true);
        if ($unended instanceof Operation) {
            throw new ResourceOperationException('tasks.copy_turn_unended', 'An earlier turn may still run in the task copy. Run task:cancel to end it with the copy.', 409,
                details: ['operation_id' => $unended->id]);
        }
    }

    /**
     * The provider and model of a new copy thread of the role, from `orbit.tasks.copy_turn_models`. T3's CLI takes no effort.
     *
     * @return array{string, string}
     */
    public static function model(TaskThreadRole $role): array
    {
        $setting = config()->string('orbit.tasks.copy_turn_models.'.$role->value);
        if (preg_match('/\A([A-Za-z][A-Za-z0-9]{0,63})\/([A-Za-z0-9][A-Za-z0-9._:-]{0,127})\z/D', $setting, $parts) !== 1) {
            throw new ResourceOperationException('tasks.copy_turn_model_invalid', 'ORBIT_TASKS_COPY_'.strtoupper($role->value).'_MODEL must read <provider>/<model>.', 503);
        }

        return [$parts[1], $parts[2]];
    }
}
