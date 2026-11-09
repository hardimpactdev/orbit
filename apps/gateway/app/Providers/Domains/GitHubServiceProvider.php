<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofObserverRelease;
use App\Infrastructure\GitHub\GitHubBranchHeadReader;
use App\Infrastructure\GitHub\GitHubGreenCommitResolver;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\GitHub\ProcessGitHubCliToken;
use App\Infrastructure\Proof\GitHubProofCommitSource;
use App\Infrastructure\Proof\PublishedProofObserverRelease;
use Illuminate\Support\ServiceProvider;

final class GitHubServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        GitHubApi::class => HttpGitHubApi::class,
        GitHubCliToken::class => ProcessGitHubCliToken::class,
        GreenCommitResolver::class => GitHubGreenCommitResolver::class,
        BranchHeadReader::class => GitHubBranchHeadReader::class,
        ProofCommitSource::class => GitHubProofCommitSource::class,
        ProofObserverRelease::class => PublishedProofObserverRelease::class,
    ];
}
