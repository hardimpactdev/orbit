<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use InvalidArgumentException;

/** Finds the newest commit that may ship, as {@see GreenCommitResolver} does, and reports the head's own check too. */
interface GreenCommitInspector
{
    /**
     * @param  list<string>  $failedShas  full SHAs that may not ship
     *
     * @throws GitHubApiException when GitHub, the App, or its installation cannot answer completely
     * @throws InvalidArgumentException when the branch, check name, or a SHA is malformed
     */
    public function inspect(
        GitHubRepository $repository,
        string $branch,
        string $checkName,
        string $deployedSha,
        array $failedShas = [],
    ): GreenCommitInspection;
}
