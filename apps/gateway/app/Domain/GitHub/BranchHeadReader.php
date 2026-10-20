<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Infrastructure\GitHub\GitHubBranchHeadReader;
use Illuminate\Container\Attributes\Bind;

/** Reads the commit a branch points at now. */
#[Bind(GitHubBranchHeadReader::class)]
interface BranchHeadReader
{
    /**
     * @return string the full SHA of the branch head
     *
     * @throws GitHubApiException when GitHub, the App, or its installation cannot answer
     */
    public function head(GitHubRepository $repository, string $branch): string;
}
