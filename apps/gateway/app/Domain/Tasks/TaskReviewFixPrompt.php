<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The prompt of the author turn that fixes the findings of one review round. The run appends the findings of the reviewer to it. */
final class TaskReviewFixPrompt
{
    public static function for(int $round, string $head): string
    {
        $short = substr($head, 0, 7);

        return <<<TEXT
        A fresh reviewer found problems in round {$round} on the pull request head {$short}. The findings follow this text, one per line as `file: reason`.

        Fix each finding in the workspace. Change nothing else. Run the Project check. End with the outcome `ready_for_review`, and say in the summary what you changed for each finding.
        TEXT;
    }
}
