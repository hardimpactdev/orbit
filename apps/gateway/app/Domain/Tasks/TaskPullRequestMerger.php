<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckRun;
use App\Models\Task;

/**
 * Reads the check runs of one commit through the Project's App installation (ADR 0203).
 * GitHub calls use repository-scoped installation tokens. No call holds a database lock.
 */
interface TaskPullRequestMerger
{
    /**
     * The check runs on exactly this commit after the merger removes superseded workflow suites.
     * The settling watch shares one read per repository and commit for a minute.
     * Null when the installation has not granted `checks: read`. Uncached reads also need `actions: read`.
     *
     * @return list<GitHubCheckRun>|null
     *
     * @throws TaskPullRequestException when the App or GitHub is unavailable
     */
    public function checkRuns(Task $group, string $sha): ?array;
}
