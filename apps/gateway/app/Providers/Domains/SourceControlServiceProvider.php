<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitHubInstallationSecretSource;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\GitHub\CachedGitHubApi;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
use Illuminate\Support\ServiceProvider;

final class SourceControlServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RepositoryDefaultBranchResolver::class => NativeRepositoryDefaultBranchResolver::class,
    ];

    public function register(): void
    {
        $this->app->tag([GitHubInstallationSecretSource::class], OperationSecretSource::class);
        // The automatic deployment tick reads branch heads every minute, so its reads keep their GitHub credentials.
        $this->app->bind(
            RemoteBranchHeads::class,
            static fn ($app): RemoteBranchHeads => GitRemoteBranchHeads::withCachedCredentials(
                $app->make(ProcessRunner::class),
                $app->make(GitHubAppStore::class),
                $app->make(CachedGitHubApi::class),
                $app->make(GitHubCliToken::class),
                $app->make(GitHubTokenCache::class),
            ),
        );
    }
}
