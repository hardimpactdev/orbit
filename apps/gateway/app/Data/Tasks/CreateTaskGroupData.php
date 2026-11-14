<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskReviewMode;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class CreateTaskGroupData extends Data
{
    /**
     * @param  list<TaskInputData>  $tasks
     * @param  array<string, string|null>  $agents  the copy turn agent values the group names, keyed by column (TaskCopyAgent)
     */
    public function __construct(
        public int $projectId,
        public string $title,
        public string $brief,
        public TaskGroupStatus $status,
        public array $tasks,
        public bool $preview = false,
        public bool $currentRecipe = false,
        public TaskOrchestration $orchestration = TaskOrchestration::External,
        public TaskReviewMode $review = TaskReviewMode::Manual,
        public array $agents = [],
    ) {}
}
