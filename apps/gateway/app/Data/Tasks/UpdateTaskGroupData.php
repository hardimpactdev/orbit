<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class UpdateTaskGroupData extends Data
{
    public function __construct(
        public ?string $title,
        public ?string $brief,
        public ?TaskGroupStatus $status,
        public ?bool $preview = null,
        /** @var array<string, string|null> the copy turn agent values to change, keyed by field; null clears one (TaskAgent) */
        public array $agents = [],
        /** A new mode for the group; only until it has a pull request (docs/reference/tasks.md#where-the-mode-comes-from). */
        public ?TaskPrMode $prMode = null,
    ) {}
}
