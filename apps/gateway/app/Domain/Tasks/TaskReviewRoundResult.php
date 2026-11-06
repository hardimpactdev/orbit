<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\TaskReviewRound;

/** Records what the reviewer turn of a review round found, when its operation ends. */
final class TaskReviewRoundResult
{
    /** @param array<string, mixed> $result the result of the finished turn */
    public static function record(Operation $operation, array $result): void
    {
        $round = TaskReviewRound::query()->where('operation_id', $operation->id)->whereNull('verdict')->first();
        if (! $round instanceof TaskReviewRound) {
            return;
        }
        $receipt = $result['receipt'] ?? null;
        $outcome = is_array($receipt) ? ($receipt['outcome'] ?? null) : null;
        $verdict = $operation->status === OperationStatus::Succeeded ? match ($outcome) {
            'approved' => TaskReviewRoundVerdict::Pass,
            'changes_requested' => TaskReviewRoundVerdict::Findings,
            default => TaskReviewRoundVerdict::Failed,
        } : TaskReviewRoundVerdict::Failed;
        $summary = is_array($receipt) && is_string($receipt['summary'] ?? null) ? mb_substr($receipt['summary'], 0, 8000) : null;
        $round->update(['verdict' => $verdict, 'summary' => $summary]);
    }
}
