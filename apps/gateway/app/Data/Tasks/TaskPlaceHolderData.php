<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One live task VM that holds a place, and the group it serves (docs/reference/tasks.md#task-places). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPlaceHolderData extends Data
{
    public function __construct(
        public string $vm,
        public string $pool,
        public ?int $groupId,
        public ?string $title,
        public ?TaskGroupStatus $status,
        public ?string $lastActivityAt,
        public ?int $activeOperationId,
    ) {}
}
