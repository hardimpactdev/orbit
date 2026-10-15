<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RequiredCheckReader;
use App\Domain\GitHub\RequiredCheckState;

/** Reads a commit's check runs of one name through the Gateway GitHub App, with a `checks: read` token. */
final readonly class GitHubRequiredCheckReader implements RequiredCheckReader
{
    public function __construct(private GitHubAppStore $store, private GitHubApi $github) {}

    public function state(GitHubRepository $repository, string $sha, string $checkName): RequiredCheckState
    {
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            throw new GitHubApiException('The Gateway GitHub App is not registered.');
        }

        $installation = $this->github->repositoryInstallation($credentials, $repository)
            ?? throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');
        $token = $this->github->repositoryChecksToken($credentials, $installation, $repository);

        return RequiredCheckState::of($this->github->checkRuns($token, $repository, $sha, $checkName), $sha, $checkName);
    }
}
