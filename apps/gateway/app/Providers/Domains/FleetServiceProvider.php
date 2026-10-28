<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Infrastructure\Fleet\GitHubCliReleaseCatalog;
use App\Infrastructure\Fleet\GitReleaseHistory;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class FleetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ReleaseHistory::class,
            static fn ($app): GitReleaseHistory => new GitReleaseHistory(
                processes: $app->make(ProcessRunner::class),
                directory: Config::string('orbit.cli_releases.git_directory'),
            ),
        );
        $this->app->bind(
            CliReleaseCatalog::class,
            static fn ($app): GitHubCliReleaseCatalog => new GitHubCliReleaseCatalog(
                apps: $app->make(GitHubAppStore::class),
                github: $app->make(GitHubApi::class),
                provenance: $app->make(ReleaseProvenance::class),
                repositoryOrigin: Config::string('orbit.cli_releases.repository'),
            ),
        );
    }
}
