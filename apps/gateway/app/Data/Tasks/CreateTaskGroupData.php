<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskReviewMode;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class CreateTaskGroupData extends Data
{
    /**
     * @param  list<TaskInputData>  $tasks
     * @param  array<string, string|null>  $agents  the copy turn agent values the group names, keyed by field (TaskAgent)
     */
    public function __construct(
        public int $projectId,
        public string $title,
        public string $brief,
        public TaskGroupStatus $status,
        public array $tasks,
        /**
         * The deliverables of a task with no subtask; null for a task that has or takes subtasks (docs/reference/tasks.md#a-task-with-no-subtask).
         *
         * @var list<array<string, string|bool|list<string>>>|null
         */
        public ?array $deliverables = null,
        public bool $preview = false,
        public bool $currentRecipe = false,
        public TaskOrchestration $orchestration = TaskOrchestration::External,
        public TaskReviewMode $review = TaskReviewMode::Manual,
        public array $agents = [],
        /** The group's own mode; null inherits the Project's, then the Gateway's (docs/reference/tasks.md#where-the-mode-comes-from). */
        public ?TaskPrMode $prMode = null,
        /** The GitHub issue of the Project's repository that the group's pull request closes (docs/reference/tasks.md#closes-the-issue). */
        public ?int $issue = null,
    ) {}
}
