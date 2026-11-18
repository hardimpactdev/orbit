<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Console\Commands\SpikeFirewallAllowCommand;
use Illuminate\Support\ServiceProvider;

/** SPIKE: registers the Durable Workflow firewall:allow spike command. */
final class SpikeDurableWorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SpikeFirewallAllowCommand::class]);
        }
    }
}
