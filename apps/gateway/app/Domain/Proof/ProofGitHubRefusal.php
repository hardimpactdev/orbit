<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubReadFailureKind;
use App\Domain\Shared\ResourceOperationException;

/**
 * The refusal of a proof whose GitHub read failed. Its code says what the caller should do, and its message keeps the reason: the
 * read, the HTTP status or the transport error class, and the reset time of a rate limit. See docs/cli/proof/run.mdx (Errors).
 */
final class ProofGitHubRefusal
{
    /** @param string $consequence what the failed read stops, for example "the commit cannot be checked" */
    public static function from(GitHubApiException $exception, string $consequence): ResourceOperationException
    {
        $failure = $exception->readFailure;
        $kind = $failure->kind ?? ($exception->isTokenRefusal() ? GitHubReadFailureKind::Refused : GitHubReadFailureKind::Transient);
        [$code, $status, $advice] = match ($kind) {
            GitHubReadFailureKind::RateLimited => ['proof.github_rate_limited', 429, 'Run the command again after the reset.'],
            GitHubReadFailureKind::Refused => ['proof.github_refused', 502, 'An operator must check the Gateway GitHub App and its access to the repository.'],
            GitHubReadFailureKind::NotFound => ['proof.github_not_found', 422, 'Running it again does not help.'],
            GitHubReadFailureKind::Transient => ['proof.github_unavailable', 503, 'Run the command again.'],
        };

        return new ResourceOperationException($code, 'GitHub could not be read, so '.$consequence.'. '.$exception->getMessage().' '.$advice, $status);
    }
}
