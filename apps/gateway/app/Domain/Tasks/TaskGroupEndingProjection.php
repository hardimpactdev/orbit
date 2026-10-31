<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record projection inside the successful, bound Task parent's result transaction. */
final readonly class TaskGroupEndingProjection
{
    public function __construct(private ProjectDirectInstanceDestroyAction $destruction) {}

    /** @param array<string, mixed> $result */
    public function execute(Operation $parent, array $result): Task
    {
        $input = $parent->input;
        $policy = $input['completion_policy'] ?? null;
        $expectedStatus = $parent->kind === 'tasks.cancel' ? 'cancelled' : 'completed';
        if ($parent->status !== OperationStatus::Succeeded || $parent->target_type !== OperationKinds::TaskGroupTarget
            || $parent->target_id !== ($input['group_id'] ?? null)
            || ! TaskGroupEndingIntent::policyMatches($parent->kind, $policy)
            || count($result) !== 6 || array_diff_key($result, array_flip(['group_id', 'group_status', 'workspace_outcome', 'destruction', 'cleanup_error_code', 'preservation'])) !== []
            || ($result['group_id'] ?? null) !== $parent->target_id
            || ! in_array($result['workspace_outcome'] ?? null, ['absent', 'removed', 'retained'], true)) {
            $this->invalid();
        }
        $retained = $result['workspace_outcome'] === 'retained';
        $code = $result['cleanup_error_code'] ?? null;
        if ($result['group_status'] !== ($retained && $policy === 'settle' ? 'unchanged' : $expectedStatus)
            || ($retained ? ! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 : $code !== null)) {
            $this->invalid();
        }

        return DB::transaction(function () use ($parent, $input, $result, $retained, $code): Task {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($parent->target_id);
            $workspace = $group->workspace()->first();
            $saved = $input['workspace'] ?? null;
            if ($group->project_id !== ($input['project_id'] ?? null)
                || ($group->taskable_id !== null && (! $workspace instanceof Instance
                    || $group->taskable_id !== $workspace->id || $group->taskable_type !== TaskableType::Instance))) {
                $this->conflict();
            }
            $this->projectPreservation($group, $input, $result, $retained);
            if ($result['workspace_outcome'] === 'absent') {
                if ($saved !== null || $workspace !== null || ($input['destruction'] ?? null) !== null || $result['destruction'] !== null) {
                    $this->invalid();
                }
            } else {
                $canonical = $input['destruction'] ?? null;
                if (! is_array($saved) || ! is_array($canonical)
                    || ($saved['instance_id'] ?? null) !== ($canonical['instance_id'] ?? null)
                    || ($saved['node_id'] ?? null) !== $parent->node_id
                    || ($canonical['node_id'] ?? null) !== $parent->node_id
                    || ($canonical['project_id'] ?? null) !== $group->project_id
                    || ($workspace !== null && ($workspace->id !== $saved['instance_id'] || $workspace->node_id !== $saved['node_id']
                        || $workspace->project_id !== $group->project_id))) {
                    $this->conflict();
                }
                if ($retained) {
                    if ($workspace === null || $result['destruction'] !== null) {
                        $this->invalid();
                    }
                } else {
                    // VM removal needs its owning same-root proof contract before this path is activated.
                    if (($saved['task_vm_id'] ?? null) !== null || ! is_array($result['destruction'])) {
                        $this->invalid();
                    }
                    $this->destruction->execute(ValidatedData::object($canonical), ValidatedData::object($result['destruction']));
                }
            }
            if (! $retained) {
                $group->taskable()->dissociate();
            }
            if ($result['group_status'] !== 'unchanged') {
                $group->status = TaskGroupStatus::from($result['group_status']);
                $group->settled_at ??= now();
            }
            $group->save();
            if ($retained) {
                TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: 'Workspace removal failed: '.ValidatedData::string($code), replaceFailure: true);
            } elseif ($group->assistance_kind === AssistanceKind::RemovalFailure) {
                TaskAssistance::apply($group);
            }

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $result
     */
    private function projectPreservation(Task $group, array $input, array $result, bool $retained): void
    {
        $expected = $input['preservation'] ?? null;
        $proof = $result['preservation'];
        if ($expected === null) {
            if ($proof !== null) {
                $this->invalid();
            }
        } elseif (! is_array($expected) || count($expected) !== 2
            || ! is_string($expected['commit'] ?? null)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $expected['commit']) !== 1
            || ($expected['branch'] ?? null) !== TaskRemoteBranch::for($group)) {
            $this->invalid();
        } elseif (! $retained || $proof !== null) {
            if (! is_array($proof) || count($proof) !== 3 || ($proof['pushed'] ?? null) !== true
                || ($proof['commit'] ?? null) !== $expected['commit'] || ($proof['branch'] ?? null) !== $expected['branch']) {
                $this->invalid();
            }
        }
        $pending = TaskFinalReview::unpublishedRemovalCommit($group);
        if (! $retained && ($input['workspace'] ?? null) !== null
            && $pending !== null && $pending !== ($expected['commit'] ?? null)) {
            $this->conflict();
        }
        if (is_array($expected) && $proof !== null) {
            TaskFinalReview::recordRemovalPush($group, ValidatedData::string($expected['commit']));
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'Task ending requires its exact parent policy and proven workspace outcome.', 422);
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('tasks.workspace_mismatch', 'The recorded Task workspace or placement changed.', 409);
    }
}
