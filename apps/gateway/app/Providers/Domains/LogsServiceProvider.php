<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Logs\LogStreamStore;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Logs\CacheLogStreamStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class LogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            LogStreamStore::class,
            static fn ($app): CacheLogStreamStore => new CacheLogStreamStore(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
    }
}
