<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskType;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

final readonly class CreateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
    ) {}

    public function execute(CreateTaskGroupData $data, ?Node $creator = null): Task
    {
        $this->requireExtension->execute();

        // Tasks publish only through the GitHub App (/reference/github-app#read-through-the-github-cli).
        if (Project::query()->find($data->projectId)?->source_access === ProjectSourceAccess::GhCli) {
            throw new ResourceOperationException(
                'tasks.github_app_required',
                'Tasks publish through the GitHub App, and this Project reads its repository through the GitHub CLI.',
            );
        }

        if ($data->status === TaskGroupStatus::Todo && $data->tasks === [] && $data->deliverables === null) {
            throw TaskGroupGuard::noSubtasks();
        }
        if ($data->status === TaskGroupStatus::Todo && $data->deliverables === []) {
            throw TaskGroupGuard::deliverablesMissing(['"'.$data->title.'"']);
        }

        $missing = array_keys(array_filter($data->tasks, static fn (TaskInputData $task): bool => $task->deliverables === []));
        if ($data->status === TaskGroupStatus::Todo && $missing !== []) {
            throw TaskGroupGuard::deliverablesMissing(array_map(static fn (int $index): string => 'position '.($index + 1).' "'.$data->tasks[$index]->title.'"', $missing));
        }

        $group = Task::topLevel()->make([
            'orchestration' => $data->orchestration,
            'review_mode' => $data->review,
            'pr_mode' => $data->prMode,
            'issue_number' => $data->issue,
            'created_by_node_id' => $creator?->id,
            'project_id' => $data->projectId,
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => $data->status,
            'preview' => $data->preview,
            'current_recipe' => $data->currentRecipe,
            ...TaskAgent::groupColumns($data->agents),
        ]);
        // The task VM columns are not null, and a release before the copy turn agent reads them: each holds the model its role
        // resolves to now. A copy turn never reads them.
        $group->implementer_model = TaskAgent::resolve($group, TaskThreadRole::Implementer)['model']['value'];
        $group->reviewer_model = TaskAgent::resolve($group, TaskThreadRole::Reviewer)['model']['value'];
        $group->save();

        foreach ($data->tasks as $index => $task) {
            Task::query()->create([
                'parent_id' => $group->id,
                'position' => $index + 1,
                'title' => $task->title,
                'brief' => $task->brief,
                'deliverables' => $task->deliverables,
                'status' => TaskStatus::Todo,
            ]);
        }

        // A task with no subtask holds its work in one row of its own (docs/reference/tasks.md#a-task-with-no-subtask).
        if ($data->deliverables !== null) {
            Task::query()->create([
                'parent_id' => $group->id,
                'type' => TaskType::Work,
                'position' => 1,
                'title' => $data->title,
                'brief' => $data->brief,
                'deliverables' => $data->deliverables,
                'status' => TaskStatus::Todo,
            ]);
        }

        return $group->refresh()->load(['project', 'tasks', 'taskable']);
    }
}
