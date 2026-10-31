<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\AgentViewCommand;
use App\Console\Commands\AgentViewPublishCommand;
use App\Console\Commands\BootstrapGatewayCommand;
use App\Console\Commands\CaddyBuildCommand;
use App\Console\Commands\CheckNodeDnsCommand;
use App\Console\Commands\CheckSwampReaderCredentialCommand;
use App\Console\Commands\ConvergeGatewayWebCommand;
use App\Console\Commands\ConvergeNodeRecipeCommand;
use App\Console\Commands\InstallSwampReaderCredentialCommand;
use App\Console\Commands\MachineRecipeCommand;
use App\Console\Commands\MigrateDockerCommand;
use App\Console\Commands\ProvisionNodeCommand;
use App\Console\Commands\ReconcilePeerDnsCommand;
use App\Console\Commands\RenderNodeProgramsCommand;
use App\Console\Commands\RepairNodeDnsCommand;
use App\Console\Commands\RetargetNodeCommand;
use App\Console\Commands\RuntimeHibernatorCommand;
use App\Console\Commands\ServePrivateDnsCommand;
use App\Domain\Gateway\GatewayCacheStore;
use App\Infrastructure\Broadcasting\ReverbBroadcaster;
use App\Models\Instance;
use App\Support\ValidatedData;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class ApplicationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            CheckSwampReaderCredentialCommand::class,
            InstallSwampReaderCredentialCommand::class,
            AgentViewCommand::class,
            AgentViewPublishCommand::class,
            BootstrapGatewayCommand::class,
            CaddyBuildCommand::class,
            CheckNodeDnsCommand::class,
            ConvergeGatewayWebCommand::class,
            ConvergeNodeRecipeCommand::class,
            MachineRecipeCommand::class,
            MigrateDockerCommand::class,
            ProvisionNodeCommand::class,
            ReconcilePeerDnsCommand::class,
            RenderNodeProgramsCommand::class,
            RepairNodeDnsCommand::class,
            RetargetNodeCommand::class,
            RuntimeHibernatorCommand::class,
            ServePrivateDnsCommand::class,
        ]);
        $cache = config('cache');
        if (! is_array($cache)) {
            throw new \UnexpectedValueException('The Gateway cache configuration is invalid.');
        }
        $cache = ValidatedData::object($cache);
        if (! $this->app->runningConsoleCommand(GatewayCacheStore::RecoveryCommands)) {
            GatewayCacheStore::assertSupported($cache, $this->app->environment(), $this->app->configurationIsCached());
        }
        // Reverb event bodies carry text as UTF-8, so non-ASCII log lines keep their length (ADR 0153).
        $this->app->make(BroadcastManager::class)->extend(
            'reverb',
            fn ($app, array $config): ReverbBroadcaster => new ReverbBroadcaster($this->pusher($config), (bool) ($config['jsonp'] ?? false)),
        );
        Relation::morphMap([
            'instance' => Instance::class,
        ]);
    }
}
