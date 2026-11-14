<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskAssistanceData;
use App\Data\Tasks\TaskMergeData;
use App\Data\Tasks\TaskPublicationData;
use App\Data\Tasks\TaskReleaseData;
use App\Data\Tasks\TasksStatusData;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPlaces;
use App\Models\Instance;
use App\Models\Task;

final readonly class ShowTasksStatusAction
{
    public function __construct(private TaskExtensionState $extension, private TaskPlaces $places) {}

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
            ->whereNotNull('pr_url')
            ->where(static fn ($query) => $query->whereHas('project', static fn ($project) => $project->where('review_and_merge', true))->orWhereNotNull('pr_branch'))
            ->orderBy('id')
            ->get()
            ->map(static fn (Task $group): TaskMergeData => TaskMergeData::fromModel($group))
            ->all());

        // Task copy groups whose work is done but whose pull request waits for a person: released by `task:publish <group>`.
        $releases = [];
        $copies = Task::topLevel()->with('project', 'taskable')->whereNull('pr_url')->where('taskable_type', (new Instance)->getMorphClass())
            ->whereNotIn('status', TaskGroupStatus::endedStatuses())->orderBy('id')->get();
        foreach ($copies as $group) {
            $publication = TaskPublicationData::fromModel($group);
            if ($publication !== null && in_array($publication->state, [TaskPublicationData::WaitingForRelease, TaskPublicationData::Refused], true)) {
                $releases[] = TaskReleaseData::fromPublication($group, $publication);
            }
        }

        return new TasksStatusData(
            enabled: $this->extension->enabled(),
            assistance: $assistance,
            lastTickAt: null,
            merges: $merges,
            capacity: $this->places->capacity(),
            releases: $releases,
        );
    }
}
