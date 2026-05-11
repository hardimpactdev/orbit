<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Contracts\RemoteShellStream;
use App\Contracts\RequestProfiler;
use App\Contracts\SiteCertificateInstaller;
use App\Contracts\ToolLogGatewayStream;
use App\Contracts\UpdateAllGatewayStream;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\ToolLogGatewayStreamClient;
use App\Http\Gateway\UpdateAllGatewayStreamClient;
use App\Services\ActivityLogCorrelation;
use App\Services\AgentIde\CoreAgentIdeMessageAdapter;
use App\Services\Ca\OrbitSiteCertificateInstaller;
use App\Services\CurlRequestProfiler;
use App\Services\Dns\LocalResolver;
use App\Services\RemoteShell\SshRemoteShell;
use App\Services\RemoteShell\SshRemoteShellStream;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Support\LocalPlatform;
use App\Support\Streaming\NullProgressReporter;
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
        $_SERVER['PAO_DISABLE'] ??= '1';

        $this->app->scoped(ActivityLogCorrelation::class);
        $this->app->singleton(GatewayConnector::class);
        $this->app->singleton(LocalResolver::class);
        $this->app->bind(ProgressReporter::class, NullProgressReporter::class);
        $this->app->bind(AgentIdeMessageAdapter::class, CoreAgentIdeMessageAdapter::class);
        $this->app->bind(RequestProfiler::class, CurlRequestProfiler::class);
        $this->app->bind(RemoteShell::class, SshRemoteShell::class);
        $this->app->bind(RemoteShellStream::class, SshRemoteShellStream::class);
        $this->app->bind(SiteCertificateInstaller::class, OrbitSiteCertificateInstaller::class);
        $this->app->bind(ToolLogGatewayStream::class, ToolLogGatewayStreamClient::class);
        $this->app->bind(UpdateAllGatewayStream::class, UpdateAllGatewayStreamClient::class);

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
