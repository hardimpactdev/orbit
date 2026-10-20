<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * The conclusions GitHub reports for a completed check run or check suite. This is the one place where Orbit decides
 * who answers for each: the handoff CI and the settling watch both ask here.
 *
 * The same conclusion means something different on a run and on a suite. A check run is one job. A run that ended
 * `cancelled` or `startup_failure` never did its work: something stopped it, or no runner started it. That is
 * infrastructure. A GitHub Actions check suite is one workflow run. Orbit judges a suite only when it completed
 * without the merge-check run, so that run can no longer come. A suite that ended `startup_failure` or `failure`
 * without it ended before its jobs existed. GitHub does that for a workflow file it cannot parse or start, and that
 * file is part of the change.
 */
enum GitHubCheckConclusion: string
{
    case Success = 'success';
    case Neutral = 'neutral';
    case Skipped = 'skipped';
    case Stale = 'stale';
    case Failure = 'failure';
    case TimedOut = 'timed_out';
    case ActionRequired = 'action_required';
    case Cancelled = 'cancelled';
    case StartupFailure = 'startup_failure';

    /** Who answers for a completed check run. Neutral, skipped, and stale runs do not fail. */
    public function ofRun(): GitHubCheckFault
    {
        return match ($this) {
            self::Success, self::Neutral, self::Skipped, self::Stale => GitHubCheckFault::None,
            self::Failure, self::TimedOut, self::ActionRequired => GitHubCheckFault::Change,
            self::Cancelled, self::StartupFailure => GitHubCheckFault::Infrastructure,
        };
    }

    /**
     * Who answers for a GitHub Actions suite that completed without the merge-check run. Every conclusion is final,
     * because GitHub makes no new suite for a commit that is not pushed again. A suite that ran its jobs to an end
     * without that run, such as `success` or `skipped`, is unexplained.
     */
    public function ofSuiteWithoutMergeCheck(): GitHubCheckFault
    {
        return match ($this) {
            self::Failure, self::StartupFailure => GitHubCheckFault::Change,
            self::Cancelled => GitHubCheckFault::Infrastructure,
            self::Success, self::Neutral, self::Skipped, self::Stale, self::TimedOut, self::ActionRequired => GitHubCheckFault::Unexplained,
        };
    }
}
