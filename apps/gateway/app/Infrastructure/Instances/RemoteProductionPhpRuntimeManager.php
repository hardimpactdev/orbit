<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Routes\RouteWebRoot;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Route;

/**
 * The dedicated PHP-FPM service of a production Instance, through the Node agent's `php.*-production-*` commands
 * (docs/reference/node-agent.md). The Gateway renders the configuration; the agent checks the runtime's marker,
 * publishes the files, and restarts or reloads the master.
 */
final readonly class RemoteProductionPhpRuntimeManager implements ProductionPhpRuntimeManager
{
    public function __construct(
        private ProductionPhpRuntimeConfigRenderer $renderer,
        private AgentCommandRunner $agents,
        private ?RemotePhpPackageManager $packages = null,
        private ?ServiceMetricsProjection $serviceMetrics = null,
    ) {}

    public function converge(Instance $instance, ?Route $activating = null): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $metrics = $this->serviceMetrics?->enabled($instance->node) ?? false;
        $applications = RouteWebRoot::servedApplications($instance, $activating);
        $configuration = $this->renderer->render($identity, $metrics, applications: $applications);
        $initialConfiguration = $this->renderer->render($identity, $metrics, initialRelease: true, applications: $applications);
        ($this->packages ?? new RemotePhpPackageManager(agents: $this->agents))->installForProductionRuntime($instance->node->loadMissing('roles'), collect([$identity->version]));

        AgentStep::run($this->agents, $instance->node, 'php.converge-production-runtime', [
            'mode' => 'converge',
            'user' => $identity->user,
            'home' => $identity->home,
            'version' => $identity->version,
            'marker' => $identity->marker(),
            'configuration' => [
                'main' => $configuration->main,
                'pool' => $configuration->pool,
                'local_defaults' => $configuration->localDefaults,
                'master_ini' => $configuration->masterIni,
                'unit' => $configuration->unit,
                'initial_pool' => $initialConfiguration->pool,
            ],
            'application' => $identity->applicationDirectory(),
            'initial_application' => $identity->applicationDirectory(initialRelease: true),
        ], 'app-prod-php-runtime-converge', 'app-prod.php_runtime_convergence_failed', 'App production');
    }

    public function remove(Instance $instance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($instance);

        AgentStep::run($this->agents, $instance->node, 'php.remove-production-runtime', [
            'user' => $identity->user,
            'version' => $identity->version,
            'marker' => $identity->marker(),
        ], 'app-prod-php-runtime-remove', 'app-prod.php_runtime_removal_failed', 'App production');
    }
}
