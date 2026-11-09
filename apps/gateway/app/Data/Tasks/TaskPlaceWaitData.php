<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Where a waiting admission stands in the task queue, and who holds the task places (docs/reference/tasks.md#task-places). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPlaceWaitData extends Data
{
    /** @param list<TaskPlaceHolderData> $holders */
    public function __construct(
        public int $operationId,
        public int $position,
        public array $holders,
    ) {}
}
