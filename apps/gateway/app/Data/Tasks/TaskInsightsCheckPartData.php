<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One part of `bin/task-check` over the handoff checks of the deliveries of a period. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsCheckPartData extends Data
{
    public function __construct(
        public string $name,
        public int $runs,
        public int $failures,
        /** The mean seconds of a run. */
        public float $seconds,
    ) {}
}
