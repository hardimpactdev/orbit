<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceLifecycleInput;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Captures Task ownership and canonical destruction in the same admission transaction. */
final readonly class TaskGroupEndingIntent
{
    public function __construct(private DirectInstanceDestroyIntent $destruction) {}

    /**
     * The caller supplies the canonical source snapshot, never a nested Operation.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    public function record(Task $group, string $kind, string $policy, ?array $snapshot): array
    {
        return DB::transaction(function () use ($group, $kind, $policy, $snapshot): array {
            if (! self::policyMatches($kind, $policy)) {
                $this->conflict();
            }
            $current = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $workspace = $current->workspace()->first();
            if ($current->taskable_id !== null && (! $workspace instanceof Instance
                || $current->taskable_id !== $workspace->id || $current->taskable_type !== TaskableType::Instance)) {
                $this->conflict();
            }
            $input = ['group_id' => $current->id, 'project_id' => $current->project_id,
                'completion_policy' => $policy, 'workspace' => null, 'destruction' => null, 'preservation' => null];
            if (! $workspace instanceof Instance) {
                if ($snapshot !== null) {
                    $this->conflict();
                }

                return $input;
            }
            if ($snapshot === null || $workspace->project_id !== $current->project_id || ! $workspace->placedOnAppDev()
                || ($snapshot['instance_id'] ?? null) !== $workspace->id
                || ($snapshot['project_id'] ?? null) !== $current->project_id
                || ($snapshot['node_id'] ?? null) !== $workspace->node_id
                || ($snapshot['force'] ?? null) !== true) {
                $this->conflict();
            }
            $members = InstanceLifecycleInput::members($snapshot);
            if (count($members) !== 1 || $members[0]['instance_id'] !== $workspace->id
                || $members[0]['project_id'] !== $current->project_id || $members[0]['node_id'] !== $workspace->node_id
                || $members[0]['environment'] !== 'development') {
                $this->conflict();
            }
            $vm = TaskVm::query()->live()->where('node_id', $workspace->node_id)->first();
            if ($vm !== null && $vm->group_id !== $current->id) {
                $this->conflict();
            }

            $commit = TaskFinalReview::unpublishedRemovalCommit($current);
            if ($commit !== null && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
                $this->conflict();
            }
            $preservation = $commit === null ? null : ['commit' => $commit, 'branch' => TaskRemoteBranch::for($current)];

            return [...$input, 'workspace' => ['instance_id' => $workspace->id, 'node_id' => $workspace->node_id,
                'task_vm_id' => $vm?->id], 'preservation' => $preservation, 'destruction' => $this->destruction->record($snapshot)];
        });
    }

    public static function policyMatches(string $kind, mixed $policy): bool
    {
        return ($kind === 'tasks.cancel' && $policy === 'cancel')
            || ($kind === 'tasks.complete' && in_array($policy, ['manual', 'settle'], true));
    }

    /** Current ownership of the actual Task root; never manufacture a lifecycle Operation.
     * @return array<string, mixed>|null
     */
    public static function admittedDestruction(Operation $parent): ?array
    {
        $input = $parent->input;
        $saved = $input['workspace'] ?? null;
        $canonical = $input['destruction'] ?? null;
        if ($parent->target_type !== OperationKinds::TaskGroupTarget || $parent->target_id !== ($input['group_id'] ?? null)
            || ! self::policyMatches($parent->kind, $input['completion_policy'] ?? null)
            || ! is_int($parent->node_id) || $parent->node_id < 1 || ! is_array($saved) || ! is_array($canonical)
            || ! is_int($saved['instance_id'] ?? null) || ($saved['node_id'] ?? null) !== $parent->node_id
            || ($canonical['instance_id'] ?? null) !== $saved['instance_id'] || ($canonical['node_id'] ?? null) !== $parent->node_id
            || ($canonical['environment'] ?? null) !== 'development' || ($canonical['force'] ?? null) !== true) {
            return null;
        }
        $group = Task::topLevel()->find($parent->target_id);
        $workspace = $group?->workspace()->with('node')->find($saved['instance_id']);
        if ($group === null || $workspace === null || $workspace->project_id !== $group->project_id
            || $group->project_id !== ($input['project_id'] ?? null) || $group->project_id !== ($canonical['project_id'] ?? null)
            || $workspace->node_id !== $parent->node_id || $workspace->status !== InstanceState::Removing || ! $workspace->placedOnAppDev()
            || ($group->taskable_id !== null && ($group->taskable_id !== $workspace->id || $group->taskable_type !== TaskableType::Instance))) {
            return null;
        }
        $members = $canonical['members'] ?? null;
        if (! is_array($members) || ! array_is_list($members) || count($members) !== 1 || ! is_array($members[0])
            || ($members[0]['instance_id'] ?? null) !== $workspace->id || ($members[0]['project_id'] ?? null) !== $group->project_id
            || ($members[0]['node_id'] ?? null) !== $parent->node_id || ($members[0]['environment'] ?? null) !== 'development') {
            return null;
        }

        return $canonical;
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('tasks.workspace_mismatch', 'Task cleanup requires its current owned development workspace and completion policy.', 409);
    }
}
