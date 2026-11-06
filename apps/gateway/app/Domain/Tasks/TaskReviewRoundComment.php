<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskReviewRound;

/** The comment that the Orbit App posts on the pull request for one review round. */
final class TaskReviewRoundComment
{
    public static function body(TaskReviewRound $round): string
    {
        $verdict = match ($round->verdict) {
            TaskReviewRoundVerdict::Pass => 'pass',
            TaskReviewRoundVerdict::Findings => 'findings',
            default => 'no verdict',
        };
        $head = substr($round->head_sha, 0, 7);
        $summary = trim((string) $round->summary);

        return "**Review round {$round->round}** of `{$head}`: **{$verdict}**".($summary === '' ? '' : "\n\n".mb_substr($summary, 0, 8000));
    }
}
