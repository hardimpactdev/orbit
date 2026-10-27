<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The tasks of one model, Project, or Gateway release that ended in the period. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsBreakdownRowData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public int $tasks,
        public int $merged,
        public ?int $tokensPerMergedPr,
        public ?int $leadTimeMs,
        public ?float $yield,
    ) {}
}
