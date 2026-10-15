<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** Reads the state of one required check on one commit, by the green-commit rules of {@see RequiredCheckState}. */
interface RequiredCheckReader
{
    /** @throws GitHubApiException when GitHub, the App, or its installation cannot answer completely */
    public function state(GitHubRepository $repository, string $sha, string $checkName): RequiredCheckState;
}
