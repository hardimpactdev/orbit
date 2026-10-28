<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Gateway\GatewayServingHost;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AgentCommandsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentContract::class, static fn (): AgentContract => new AgentContract(Config::string('orbit.agent.contract')));
        $this->app->singleton(AgentDelivery::class, static fn (): AgentDelivery => new AgentDelivery(Config::string('orbit.agent.delivery'), Config::string('orbit.home').'/agent-artifacts'));
        $this->app->bind(AgentTransports::class, static fn ($app): AgentTransports => new AgentTransports(
            $app->make(GatewayServingHost::class),
            $app->make(ProcessRunner::class),
            $app->make(SshExecutor::class),
            $app->make(SshKeyProvider::class),
            $app->make(KnownHostsStore::class),
            Config::string('orbit.home'),
        ));
        $this->app->bind(AgentCommandRunner::class, NativeAgentCommandRunner::class);
        $this->app->bind(GuestAgentCommandRunner::class, NativeAgentCommandRunner::class);
        $this->app->bind(WorkspaceAgentCommandRunner::class, NativeAgentCommandRunner::class);
        $this->app->bind(AdmissionAgentRunner::class, NativeAgentCommandRunner::class);
    }
}
