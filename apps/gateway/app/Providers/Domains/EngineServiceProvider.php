<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Console\Commands\EngineConsumeCommand;
use App\Console\Commands\EngineSetupCommand;
use App\Domain\Engine\MachineBus;
use App\Infrastructure\Engine\EngineBus;
use Illuminate\Support\ServiceProvider;

/** The Gateway state machine and its machine bus (ADR 0271; docs/reference/gateway-engine.md). */
final class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One connection per process for publishes; the consumer opens its own for subscriptions.
        $this->app->singleton(EngineBus::class);
        $this->app->bind(MachineBus::class, EngineBus::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EngineConsumeCommand::class, EngineSetupCommand::class]);
        }
    }
}
