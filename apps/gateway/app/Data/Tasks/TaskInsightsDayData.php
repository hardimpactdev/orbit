<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsDayData extends Data
{
    public function __construct(
        public string $date,
        public int $merged,
        public int $notMerged,
    ) {}
}
