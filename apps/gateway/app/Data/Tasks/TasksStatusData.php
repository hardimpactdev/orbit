<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TasksStatusData extends Data
{
    /**
     * @param  list<TaskAssistanceData>  $assistance
     * @param  string|null  $lastTickAt  Retired scheduler observation; always null.
     * @param  list<TaskMergeData>  $merges  Open tasks with a pull request that Orbit reviews and merges, in ascending id order.
     */
    public function __construct(
        public bool $enabled,
        public array $assistance,
        public ?string $lastTickAt,
        public array $merges,
        public TaskCapacityData $capacity,
    ) {}
}
