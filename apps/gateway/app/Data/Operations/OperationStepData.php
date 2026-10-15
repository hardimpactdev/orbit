<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Models\OperationStep;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One step of an operation (docs/reference/operations.md#the-record). */
#[MapOutputName(SnakeCaseMapper::class)]
final class OperationStepData extends Data
{
    public function __construct(
        public string $name,
        public int $position,
        public string $status,
        public bool $resumable,
        public int $sequence,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $errorCode,
        public ?string $output,
        public bool $outputTruncated,
    ) {}

    public static function fromModel(OperationStep $step): self
    {
        return new self(
            name: $step->name,
            position: $step->position,
            status: $step->status->value,
            resumable: $step->resumable,
            sequence: $step->sequence,
            startedAt: $step->started_at?->format(DateTimeInterface::ATOM),
            finishedAt: $step->finished_at?->format(DateTimeInterface::ATOM),
            errorCode: $step->error_code,
            output: $step->output,
            outputTruncated: $step->output_truncated,
        );
    }
}
