<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\Tasks\Pi\PiModel;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admitted task and session metadata. Swamp owns every execution decision. */
final readonly class TaskTurnIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskCheckedCandidate $candidates) {}

    public function start(Task $group, TaskTurnData $data, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.turn')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($group, $data, $caller, $request): Operation {
            $group->setRawAttributes(Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (($existing = $this->idempotency->replay('tasks.turn')) !== null) {
                return $existing;
            }
            $group->load('taskable', 'project');
            $instance = $group->taskable;
            $vm = TaskVm::forGroup($group);
            if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
            }
            if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
                || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
                throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
            }
            if (in_array($instance->node_id, [1, 18], true)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Native task command steps require a worker on the admitted Node.', 503);
            }
            $task = $group->tasks()->findOrFail($data->subtask);
            $current = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->value('id');
            if ($task->id !== $current) {
                throw new ResourceOperationException('tasks.subtask_not_current', 'Only the current open subtask can run.', 409);
            }
            $candidate = null;
            if ($data->role === 'reviewer') {
                $check = TaskCheck::query()->where('task_id', $task->id)->find($data->check);
                if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
                    throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires the current subtask checked candidate.', 422);
                }
                $candidate = $check->commit_sha;
            }
            $thread = $data->thread === 'new' ? null : AgentThread::query()->where('task_group_id', $group->id)->find((int) $data->thread);
            if ($data->thread !== 'new' && (! $thread instanceof AgentThread || $thread->role !== $data->threadRole()->value || $thread->isPending())) {
                throw new ResourceOperationException('tasks.thread_role_mismatch', 'The thread must belong to this group and role.', 422);
            }
            if ($thread !== null && $data->model !== null) {
                throw new ResourceOperationException('tasks.model_fixed', 'A continued thread keeps its model.', 422);
            }
            $model = $thread->model ?? $data->model ?? ($data->threadRole() === TaskThreadRole::Implementer ? $group->implementer_model : $group->reviewer_model);
            try {
                $model = PiModel::onlyProvider($model, 'orbit-task-vm');
            } catch (AgentDriverException $exception) {
                throw new ResourceOperationException('tasks.model_refused', $exception->getMessage(), 422);
            }
            $analysis = null;
            if ($data->analysis !== null) {
                $evidence = Operation::query()->where('kind', 'tasks.analyze')->where('target_type', 'task_group')->where('target_id', $group->id)->find($data->analysis['operation']);
                if ($evidence?->status->value !== 'succeeded' || ($evidence->input['subtask'] ?? null) !== $task->id
                    || ($evidence->input['plan_sha256'] ?? null) !== $data->analysis['plan_sha256'] || ! in_array($evidence->result['outcome'] ?? null, ['ready', 'action_required'], true)) {
                    throw new ResourceOperationException('tasks.analysis_not_usable', 'Analysis must identify this subtask and exact plan.', 422);
                }
                $analysis = ['input' => $evidence->input, 'result' => $evidence->result];
            }
            if (! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
                throw new ResourceOperationException('tasks.pi_credential_unavailable', 'The task VM Pi credential is unavailable.', 409);
            }
            $piToken = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token);
            $thread ??= AgentThread::query()->create([
                'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
                'driver' => 'pi', 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => (string) Str::uuid(),
                'role' => $data->threadRole()->value, 'model' => $model,
                'effort' => config('orbit.tasks.'.$data->threadRole()->value.'_effort', 'high'),
            ]);
            ($data->threadRole() === TaskThreadRole::Implementer ? $task : $group)->update([$data->threadRole()->value.'_agent_thread_id' => $thread->id]);

            return $this->start->execute('tasks.turn', 'task_group', $group->id, $instance->node_id, [
                ...$data->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'checkout_path' => $instance->checkout_path, 'thread_id' => $thread->id, 'session_id' => $thread->external_id, 'new_session' => $data->thread === 'new',
                'model' => $model, 'effort' => $thread->effort, 'pi_port' => app(PiConnection::class)->port(),
                'pi_host' => $instance->node->wireguard_ip, 'candidate' => $candidate,
                'deliverables' => $task->deliverables ?? [], 'analysis_evidence' => $analysis,
                'execution_secrets' => ['pi_token' => $piToken],
            ], $caller, $request);
        });
    }
}
