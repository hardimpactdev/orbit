<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskData;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Models\Task;

final readonly class CreateTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(Task $group, CreateTaskData $data): Task
    {
        $this->requireExtension->execute();

        $status = $group->refresh()->status;
        if (in_array($status, TaskGroupStatus::endedStatuses(), true)) {
            throw TaskGroupGuard::groupClosed();
        }

        if ($data->deliverables === [] && $status !== TaskGroupStatus::Backlog) {
            throw TaskGroupGuard::deliverablesRequired();
        }

        TaskGroupGuard::supportOrchestration($group->groupOrchestration(), false, [$data->topology]);
        TaskTopology::assertFits([...TaskTopology::declarations($group), $data->topology]);
        $position = StoredInteger::fromOrZero($group->tasks()->max('position')) + 1;

        return Task::query()->create([
            'parent_id' => $group->id,
            'position' => $position,
            'title' => $data->title,
            'brief' => $data->brief,
            'deliverables' => $data->deliverables,
            'topology' => $data->topology,
            'status' => TaskStatus::Todo,
        ]);
    }
}
