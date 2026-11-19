<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostServiceProvider;

/**
 * Runs Laravel Boost only as the MCP server of coding agents: for `boost:mcp`
 * and the `boost:execute-tool` subprocess it starts per tool call, whatever
 * the environment. Requests, Horizon and the scheduler never load it.
 */
final class GatewayBoostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningConsoleCommand('boost:mcp', 'boost:execute-tool')) {
            return;
        }

        if (! class_exists(BoostServiceProvider::class)) {
            return;
        }

        $this->app->register(new class($this->app) extends BoostServiceProvider
        {
            protected function shouldRun(): bool
            {
                return true;
            }

            protected function registerRoutes(): void {}
        });
    }
}
