<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The `capacity` of `task:status`: the places of every task VM host and the task queue (docs/reference/tasks.md#task-places). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskCapacityData extends Data
{
    /**
     * @param  list<TaskPlaceHostData>  $hosts
     * @param  list<TaskPlaceQueueData>  $queue
     */
    public function __construct(
        public array $hosts,
        public array $queue,
    ) {}
}
