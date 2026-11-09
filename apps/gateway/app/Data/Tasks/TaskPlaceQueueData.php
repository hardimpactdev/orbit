<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One admission waiting for a task place, front first (docs/reference/tasks.md#task-places). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPlaceQueueData extends Data
{
    public function __construct(
        public int $position,
        public int $operationId,
        public string $kind,
        public int $groupId,
        public ?string $title,
        public ?string $waitingSince,
    ) {}
}
