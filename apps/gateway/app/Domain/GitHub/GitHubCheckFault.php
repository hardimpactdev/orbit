<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * Who answers for a completed check. {@see GitHubCheckConclusion} is the one place that decides it from GitHub's
 * conclusion, for check runs and check suites alike.
 */
enum GitHubCheckFault
{
    /** The check did not fail. */
    case None;

    /** The pushed change is at fault: its code, its tests, or its workflow files. */
    case Change;

    /** CI did not run to completion, such as a cancelled job. The change is not at fault. */
    case Infrastructure;

    /**
     * CI completed without the check, and Orbit cannot tell why: a renamed or removed job, a narrower workflow trigger,
     * or the Project's own setup. Someone must look.
     */
    case Unexplained;
}
