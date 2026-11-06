<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskReviewRound;

/** The prompt of the author turn that fixes the findings of one review round. */
final class TaskReviewFixPrompt
{
    public static function for(TaskReviewRound $round): string
    {
        $findings = trim((string) $round->summary);
        $head = substr($round->head_sha, 0, 7);

        return <<<TEXT
        A fresh reviewer found problems in round {$round->round} on the pull request head {$head}:

        {$findings}

        Fix each finding in the workspace. Change nothing else. Run the Project check. End with the outcome `ready_for_review`, and say in the summary what you changed for each finding.
        TEXT;
    }
}
