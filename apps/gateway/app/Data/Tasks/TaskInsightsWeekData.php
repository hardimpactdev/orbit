<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsWeekData extends Data
{
    public function __construct(
        public string $weekStart,
        public int $tasks,
        public int $merged,
        public ?int $tokensPerMergedPr,
    ) {}
}
