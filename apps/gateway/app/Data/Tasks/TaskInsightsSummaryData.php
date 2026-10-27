<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The numbers of the tasks that ended in one period. Shares are 0 to 1; null means no task had the data. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsSummaryData extends Data
{
    /** @param  array<string, int>  $questionsByCause */
    public function __construct(
        public int $tasks,
        public int $merged,
        public int $excluded,
        public int $tokens,
        public ?float $costUsd,
        public ?int $tokensPerMergedPr,
        public ?float $costPerMergedPr,
        public ?int $leadTimeMs,
        public ?int $queueMs,
        public ?int $workMs,
        public ?int $mergeWaitMs,
        public ?float $yield,
        public ?float $prFirstPass,
        public ?float $wastedShare,
        public ?float $subtaskFirstPass,
        public ?float $reviewRounds,
        public ?float $handoffFirstTry,
        public ?float $questionsPerTask,
        public array $questionsByCause,
        public ?float $cacheHit,
        public ?int $tokensPerHundredLines,
    ) {}
}
