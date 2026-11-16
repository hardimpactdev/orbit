<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class UpdateTaskData extends Data
{
    public function __construct(
        public ?string $title,
        public ?string $brief,
        public ?int $position,
        /** @var list<array<string, string|bool|list<string>>>|null null leaves the deliverables unchanged */
        public ?array $deliverables = null,
        public ?TaskStatus $status = null,
        /** why a correction after a failed check replaces a started subtask's deliverables; refused on any other update */
        public ?string $reason = null,
    ) {}
}
