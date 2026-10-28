<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskAssistanceData;
use App\Data\Tasks\TaskMergeData;
use App\Data\Tasks\TasksStatusData;
use App\Data\Tasks\TaskWorkspaceActorData;
use App\Data\Tasks\TaskWorkspaceLeaseData;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Instance;
use App\Models\Task;

final readonly class ShowTasksStatusAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): TasksStatusData
    {
        $assistance = array_values(Task::topLevel()
            ->with('project')
            ->where('assistance_requested', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (Task $group): TaskAssistanceData => TaskAssistanceData::fromModel($group))
            ->all());

        $merges = array_values(Task::topLevel()
            ->with('project')
            ->whereNotIn('status', TaskGroupStatus::endedStatuses())
            ->where(static fn ($query) => $query->whereHas('project', static fn ($project) => $project->where('review_and_merge', true))->orWhereNotNull('pr_branch'))
            ->orderBy('id')
            ->get()
            ->map(static fn (Task $group): TaskMergeData => TaskMergeData::fromModel($group))
            ->all());

        return new TasksStatusData(
            enabled: $this->extension->enabled(),
            assistance: $assistance,
            lastTickAt: null,
            merges: $merges,
            workspaceLeases: $this->workspaceLeases(),
        );
    }

    /** @return list<TaskWorkspaceLeaseData> */
    private function workspaceLeases(): array
    {
        $leases = app(TaskWorkspaceLease::class)->status();
        $instances = Instance::query()->whereIn('id', array_column($leases, 'instance_id'))->get()->keyBy('id');
        $groups = Task::topLevel()->with('project')->whereIn('id', array_column($leases, 'group_id'))->get()->keyBy('id');
        $result = [];
        foreach ($leases as $lease) {
            $instance = $instances->get($lease['instance_id']);
            $group = $groups->get($lease['group_id']);
            if (! $instance instanceof Instance || ! $group instanceof Task) {
                continue;
            }
            $result[] = new TaskWorkspaceLeaseData(
                instanceId: $instance->id,
                instanceName: $instance->name,
                groupId: $group->id,
                projectCode: $group->project->code,
                holder: $lease['holder'] === null ? null : TaskWorkspaceActorData::fromLease($lease['holder']),
                waiters: array_map(TaskWorkspaceActorData::fromLease(...), $lease['waiters']),
            );
        }

        return $result;
    }
}
