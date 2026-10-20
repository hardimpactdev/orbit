<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\FleetConvergeCommand;
use App\Console\Commands\ResolveDesiredFleetStateCommand;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Fleet\ReleaseHistory;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PeerDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\RouteResidueFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Fleet\NativeFleetConvergeUnits;
use App\Infrastructure\Fleet\StaticCliRelease;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/** The fleet rollout of ADR 0202: CLI install, footprint re-apply, the rollout, and its units. */
final class FleetServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->bindStaticCliRelease();
        // Bound here because a constructor gives each of them a default (see ApplicationServiceProvider).
        $this->app->bind(FleetConvergeUnits::class, NativeFleetConvergeUnits::class);
        $this->app->bind(FleetReleaseLag::class);
        $this->app->bind(
            AgentReportedVersions::class,
            static fn (Application $app): AgentReportedVersions => new AgentReportedVersions(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->tag([
            AgentFootprintArtifact::class,
            CaddyPackageFootprintArtifact::class,
            CaddyFootprintArtifact::class,
            PeerDnsFootprintArtifact::class,
            PrivateDnsFootprintArtifact::class,
            RouteResidueFootprintArtifact::class,
            TmpfilesFootprintArtifact::class,
        ], NodeFootprintArtifact::class);
    }

    public function boot(): void
    {
        $this->commands([
            FleetConvergeCommand::class,
            ResolveDesiredFleetStateCommand::class,
        ]);
    }

    /** Test-only: a disposable topology names its CLI release in a local manifest ({@see StaticCliRelease}). */
    private function bindStaticCliRelease(): void
    {
        $path = Config::get('fleet.static_cli_release');

        if (! is_string($path) || $path === '') {
            return;
        }

        $release = new StaticCliRelease($path);
        $this->app->instance(ReleaseHistory::class, $release);
        $this->app->instance(CliReleaseCatalog::class, $release);
    }
}
