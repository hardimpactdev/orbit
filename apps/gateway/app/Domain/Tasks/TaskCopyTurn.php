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
use App\Support\ValidatedData;
use Illuminate\Support\Collection;
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
        $task = $this->operations->requireCurrentSubtask($group, $data->subtask);
        $role = $data->threadRole();
        $instance = $workspace->instance;
        $runtime = 'instance:'.$instance->id;
        // The role's thread in this copy: the implementer's of the current subtask, or the group's reviewer thread. A turn continues it
        // unless the caller asks for a new one; `--thread=ID` may only name it.
        $recorded = $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id;
        $current = $recorded === null ? null : AgentThread::query()->where('task_group_id', $group->id)->where('role', $role->value)
            ->where('driver', 't3')->where('runtime_key', $runtime)->find($recorded);
        if ($data->thread !== null && $data->thread !== 'new' && (int) $data->thread !== $current?->id) {
            throw new ResourceOperationException('tasks.thread_role_mismatch', 'The thread must be the role\'s thread that this turn continues in the task copy.', 422);
        }
        $thread = $data->thread === 'new' ? null : $current;
        self::refuseUnended($group, $thread?->id);
        $candidate = null;
        if ($role === TaskThreadRole::Reviewer) {
            $check = TaskCheck::query()->where('task_id', $task->id)->find($data->check);
            if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
                throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires the current subtask checked candidate.', 422);
            }
            $candidate = (string) $check->commit_sha;
        }
        $provider = null;
        if ($thread === null) {
            [$model, $effort] = self::modelAndEffort($role);
            $provider = InstanceContainer::claudeInstance($effort);
            $thread = AgentThread::query()->create([
                'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
                'driver' => 't3', 'runtime_key' => $runtime, 'external_id' => (string) Str::uuid(),
                'role' => $role->value, 'model' => $model, 'effort' => $effort,
            ]);
        }
        ($role === TaskThreadRole::Implementer ? $task : $group)->update([$role->value.'_agent_thread_id' => $thread->id]);
        app(TaskBroadcasts::class)->threadChanged($thread->id);
        $target = $workspace->intent();
        // The phase checks the candidate out for the reviewer and the task branch out again after it (docs/cli/task/copy-turn.mmd).
        $prompt = $candidate === null ? $data->prompt : $data->prompt."\n\nThe checked commit under review: {$candidate}. The checkout holds it, "
            .'detached. Orbit checks the task branch out again after this turn and discards every change in the checkout.';

        return [
            ...$data->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'runtime' => $target['runtime'], 'branch' => $target['branch'], 'thread_id' => $thread->id, 'candidate' => $candidate,
            'turn' => ['t3' => InstanceContainer::T3, 'thread' => $provider === null ? 'continue' : 'new', 'provider' => $provider, 'model' => $thread->model,
                'candidate' => $candidate, 'seconds' => self::TurnSeconds, 'title' => 'Orbit task '.$group->id.' thread '.$thread->id,
                'prompt' => TaskTurnPrompt::compose($task, $prompt)],
        ];
    }

    /**
     * Refuses more work on a group while an earlier turn may still run in its copy or left its checkout to the reviewer. T3's CLI cannot
     * stop a run, so a turn whose run started and whose result does not show T3's run over (its bound cut the wait, a cancel or a lost
     * phase) leaves the agent writing in the checkout; a reviewer turn whose checkout is not restored leaves it off the task branch. The
     * result's `ended` says both. Only `task:cancel` ends it, with the copy. Called by the turn and by the check, the publish and the
     * proof of a copy.
     *
     * T3 runs the turns of one thread in order, so only the latest turn of each thread counts. A turn that ended waiting for an answer
     * writes nothing until it gets one: it holds every other turn and check, but not the turn that continues its own thread.
     */
    public static function refuseUnended(Task $group, ?int $continuing = null): void
    {
        $unended = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', OperationKinds::TasksCopyTurn)->whereIn('status', OperationStatus::terminalValues())->orderBy('id')->get()
            ->groupBy(static fn (Operation $turn): int => ValidatedData::integer($turn->input['thread_id']))
            ->map(static fn (Collection $turns): Operation => $turns->last())
            ->first(static fn (Operation $turn, int $thread): bool => ($turn->state['swamp_run_id'] ?? null) !== null && ($turn->result['ended'] ?? null) !== true
                && ! (($turn->result['state'] ?? null) === AgentThreadState::AskingForInput->value && $thread === $continuing));
        if ($unended instanceof Operation) {
            throw new ResourceOperationException('tasks.copy_turn_unended', 'An earlier turn may still run in the task copy or left its checkout unrestored. Run task:cancel to end it with the copy.', 409,
                details: ['operation_id' => $unended->id]);
        }
    }

    /**
     * The model and effort of a new copy thread of the role: the model from `orbit.tasks.copy_turn_models`, the effort from the role's
     * effort setting, which a task VM thread reads too. The effort must be one the copy's T3 has a provider instance for.
     *
     * @return array{string, string}
     */
    public static function modelAndEffort(TaskThreadRole $role): array
    {
        $model = config()->string('orbit.tasks.copy_turn_models.'.$role->value);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $model) !== 1) {
            throw new ResourceOperationException('tasks.copy_turn_model_invalid', 'ORBIT_TASKS_COPY_'.strtoupper($role->value).'_MODEL must be a model name.', 503);
        }
        $effort = config('orbit.tasks.'.$role->value.'_effort');
        if (! in_array($effort, InstanceContainer::ClaudeEfforts, true)) {
            throw new ResourceOperationException('tasks.copy_turn_effort_invalid', 'ORBIT_TASKS_'.strtoupper($role->value).'_EFFORT must be one of '
                .implode(', ', InstanceContainer::ClaudeEfforts).' for a turn in a task copy.', 503);
        }

        return [$model, $effort];
    }
}
