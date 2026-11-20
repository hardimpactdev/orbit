<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The [handoffs](/reference/tasks#handoffs) of the gated tasks that ended in one period. Null means no task had the data. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsHandoffsData extends Data
{
    /**
     * @param  array<string, int>  $rejectedDeliveries  each rejection reason of a delivery, zeros included
     * @param  list<TaskInsightsCheckPartData>  $checkParts  by name
     */
    public function __construct(
        public int $tasks,
        public ?float $handoffsPerSubtask,
        public ?float $firstReviewApproval,
        public ?float $findingsPerFirstReview,
        public ?float $questionsPerTask,
        public array $rejectedDeliveries,
        public array $checkParts,
        /** Ended tasks with a rejected handoff that no later accepted handoff of its subtask and type follows; it must be 0. */
        public int $unresolvedTasks,
    ) {}
}
