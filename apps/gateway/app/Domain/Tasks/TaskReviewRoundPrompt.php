<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The prompt of the fresh reviewer of one review round. */
final class TaskReviewRoundPrompt
{
    public static function for(string $head, int $round): string
    {
        return <<<TEXT
        You are the reviewer of round {$round} for the pull request head {$head}. You took no part in the spec, the code or an earlier round.

        Check out nothing else: the workspace is already at the handoff candidate of this head. Read the pull request diff, the chart, the docs page, the proof cases and the CI result. Judge them against the review rules in `.agents/skills/reviewing-pull-requests/SKILL.md`, when the file exists, and otherwise against these: the chart, the page, the cases and the code agree; every changed behavior has a test; nothing outside the brief changed.

        End with the outcome `approved` when you find nothing to fix. End with `changes_requested` when you find something, and list each finding in the summary as `file: reason`, one per line. Change no file.
        TEXT;
    }
}
