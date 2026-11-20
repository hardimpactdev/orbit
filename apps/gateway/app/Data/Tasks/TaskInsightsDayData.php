<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The tasks that ended in one day of the period, and the tile numbers of their summary alone. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsDayData extends Data
{
    public function __construct(
        public string $date,
        public int $merged,
        public int $notMerged,
        public ?int $tokens,
        public ?int $tokensPerMergedPr,
        public ?float $wastedShare,
        public ?int $leadTimeMs,
        public ?int $deliveryTimeMs,
        public ?float $prFirstPass,
        public ?int $prMergeWaitMs,
        public ?float $reviewRounds,
    ) {}

    public static function of(string $date, TaskInsightsSummaryData $summary): self
    {
        return new self($date, $summary->merged, $summary->tasks - $summary->merged, $summary->tokens, $summary->tokensPerMergedPr, $summary->wastedShare,
            $summary->leadTimeMs, $summary->deliveryTimeMs, $summary->prFirstPass, $summary->prMergeWaitMs, $summary->reviewRounds);
    }
}
