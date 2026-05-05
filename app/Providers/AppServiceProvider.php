<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\RemoteShell;
use App\Contracts\RequestProfiler;
use App\Http\Gateway\GatewayConnector;
use App\Services\ActivityLogCorrelation;
use App\Services\CurlRequestProfiler;
use App\Services\Dns\LocalResolver;
use App\Services\RemoteShell\SshRemoteShell;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Support\LocalPlatform;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->scoped(ActivityLogCorrelation::class);
        $this->app->singleton(GatewayConnector::class);
        $this->app->singleton(LocalResolver::class);
        $this->app->bind(RequestProfiler::class, CurlRequestProfiler::class);
        $this->app->bind(RemoteShell::class, SshRemoteShell::class);

        $this->app->bind(TrustStoreInstaller::class, function ($app): TrustStoreInstaller {
            $platform = $app->make(LocalPlatform::class);

            return match ($platform->current()) {
                'macos' => new MacOsTrustStoreInstaller,
                'linux' => new LinuxTrustStoreInstaller,
                default => throw new RuntimeException('Unsupported platform for trust store operations.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
