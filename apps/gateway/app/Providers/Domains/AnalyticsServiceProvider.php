<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\AnalyticsDatabaseConnectionGuard;
use App\Domain\Analytics\AnalyticsManagementCredentialsSource;
use App\Domain\Analytics\AnalyticsProcessOwnership;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Infrastructure\Analytics\NativeAnalyticsClickhouseConfigurationManager;
use App\Infrastructure\Analytics\NativeAnalyticsPublicationManager;
use App\Infrastructure\Analytics\NativeAnalyticsRoleSettingsRepository;
use App\Infrastructure\Analytics\NativeAnalyticsSecretManager;
use App\Infrastructure\Analytics\NativeAnalyticsStatsKeyStore;
use App\Infrastructure\Analytics\NativeAnalyticsTrackingRouteProjector;
use App\Infrastructure\Analytics\NativePlausibleRuntimeLifecycle;
use App\Infrastructure\Analytics\PlausibleCommunityEditionStatsDriver;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use Illuminate\Support\ServiceProvider;

final class AnalyticsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AnalyticsRoleSettingsRepository::class => NativeAnalyticsRoleSettingsRepository::class,
        AnalyticsSecretManager::class => NativeAnalyticsSecretManager::class,
        PlausibleRuntimeLifecycle::class => NativePlausibleRuntimeLifecycle::class,
        AnalyticsPublicationManager::class => NativeAnalyticsPublicationManager::class,
        AnalyticsClickhouseConfigurationManager::class => NativeAnalyticsClickhouseConfigurationManager::class,
        AnalyticsTrackingRouteProjector::class => NativeAnalyticsTrackingRouteProjector::class,
        AnalyticsStatsDriver::class => PlausibleCommunityEditionStatsDriver::class,
        AnalyticsStatsKeyStore::class => NativeAnalyticsStatsKeyStore::class,
    ];

    public function register(): void
    {
        $this->app->tag([AnalyticsManagementCredentialsSource::class], OperationSecretSource::class);
        $this->app->tag([AnalyticsCaddySiteSource::class], NodeCaddySiteSource::class);
        $this->app->tag([AnalyticsDatabaseConnectionGuard::class], DatabaseConnectionRemovalGuard::class);
        $this->app->tag([AnalyticsProcessOwnership::class], ProcessRemovalGuard::class);
    }
}
