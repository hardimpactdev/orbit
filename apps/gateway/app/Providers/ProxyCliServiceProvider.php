<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\ProxyCli\ProxyCliAccountControlClient;
use App\Domain\ProxyCli\ProxyCliCache;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\ProxyCli\ArrayProxyCliCache;
use App\Infrastructure\ProxyCli\HttpProxyCliAccountControlClient;
use App\Infrastructure\ProxyCli\NativeProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\NativeProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteSource;
use App\Infrastructure\ProxyCli\ProxyCliModelKeyIssuer;
use App\Infrastructure\ProxyCli\RecordingProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\RecordingProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\ValkeyProxyCliCache;
use App\Models\DatabaseConnection;

/** The proxycli extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class ProxyCliServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ModelKeyIssuer::class => ProxyCliModelKeyIssuer::class,
        ProxyCliAccountControlClient::class => HttpProxyCliAccountControlClient::class,
    ];

    public function name(): string
    {
        return 'proxycli';
    }

    public function description(): string
    {
        return 'Collects CLIProxyAPI provider quota and shows it on the Quota page.';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ArrayProxyCliCache::class);
        $this->app->singleton(ProxyCliCache::class, function ($app): ProxyCliCache {
            if ($app->environment('testing')) {
                return $app->make(ArrayProxyCliCache::class);
            }

            $slug = $app->make(ProxyCliState::class)->cacheConnection();
            $connection = is_string($slug)
                ? DatabaseConnection::query()->where('slug', $slug)->first()
                : null;

            return $connection instanceof DatabaseConnection
                ? new ValkeyProxyCliCache($connection)
                : $app->make(ArrayProxyCliCache::class);
        });
        $this->app->singleton(ProxyCliSnapshotStore::class);
        if ($this->app->environment('testing')) {
            $this->app->singleton(RecordingProxyCliRuntimeLifecycle::class);
            $this->app->singleton(RecordingProxyCliPublicationManager::class);
            $this->app->singleton(ProxyCliRuntimeLifecycle::class, static fn ($app): ProxyCliRuntimeLifecycle => $app->make(RecordingProxyCliRuntimeLifecycle::class));
            $this->app->singleton(ProxyCliPublicationManager::class, static fn ($app): ProxyCliPublicationManager => $app->make(RecordingProxyCliPublicationManager::class));
        } else {
            $this->app->bind(ProxyCliRuntimeLifecycle::class, NativeProxyCliRuntimeLifecycle::class);
            $this->app->bind(ProxyCliPublicationManager::class, NativeProxyCliPublicationManager::class);
        }
        $this->contribute(ContributedCaddySiteSource::class, ProxyCliCaddySiteSource::class);
    }
}
