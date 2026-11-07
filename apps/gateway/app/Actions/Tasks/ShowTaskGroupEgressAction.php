<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskGroupEgress;
use Illuminate\Database\Eloquent\Collection;

/** The outside hosts a task group's VM connected to, by destination, port and protocol (docs/reference/task-vm-egress-log.md). */
final readonly class ShowTaskGroupEgressAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    /** @return Collection<int, TaskGroupEgress> */
    public function execute(Task $group): Collection
    {
        $this->requireExtension->execute();

        return TaskGroupEgress::query()->where('task_id', $group->id)
            ->orderBy('destination')->orderBy('port')->orderBy('protocol')->get();
    }
}
