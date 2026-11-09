<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The task and CI places of one task VM host (docs/reference/tasks.md#task-places). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPlaceHostData extends Data
{
    /** @param list<TaskPlaceHolderData> $holders */
    public function __construct(
        public int $nodeId,
        public string $node,
        public int $taskPlaces,
        public int $taskUsed,
        public int $taskFree,
        public int $ciPlaces,
        public int $ciUsed,
        public int $ciFree,
        public array $holders,
    ) {}
}
