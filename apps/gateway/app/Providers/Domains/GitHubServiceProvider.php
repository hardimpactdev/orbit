<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GreenCommitResolver;
use App\Infrastructure\GitHub\GitHubBranchHeadReader;
use App\Infrastructure\GitHub\GitHubGreenCommitResolver;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\GitHub\ProcessGitHubCliToken;
use Illuminate\Support\ServiceProvider;

final class GitHubServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        GitHubApi::class => HttpGitHubApi::class,
        GitHubCliToken::class => ProcessGitHubCliToken::class,
        GreenCommitResolver::class => GitHubGreenCommitResolver::class,
        BranchHeadReader::class => GitHubBranchHeadReader::class,
    ];
}
