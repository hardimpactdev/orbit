<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
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
    /** The cache refusals that each have their own error code. */
    private const array CACHE_ERRORS = [
        'php.cache_socket_unavailable' => ['app-prod.php_cache_socket_unavailable', 'The production PHP cache socket is unavailable.'],
        'php.cache_association_invalid' => ['app-prod.php_cache_association_invalid', 'The production PHP cache service association is invalid.'],
        'php.cache_reset_rejected' => ['app-prod.php_cache_reset_rejected', 'The production PHP cache reset was rejected.'],
        'php.cache_reset_pending' => ['app-prod.php_cache_reset_pending', 'The production PHP cache reset did not complete before the deadline.'],
    ];

    public function __construct(
        private ProductionPhpRuntimeConfigRenderer $renderer,
        private AgentCommandRunner $agents,
        private ?RemotePhpPackageManager $packages = null,
        private int $cacheDeadlineSeconds = 30,
        private ?ServiceMetricsProjection $serviceMetrics = null,
    ) {}

    public function converge(Instance $instance, ?Route $activating = null): void
    {
        $this->convergeWithTuning($instance, activating: $activating);
    }

    public function convergeMonitoring(Instance $instance, bool $enabled): void
    {
        $this->convergeWithTuning($instance, $enabled, 'monitor');
    }

    private function convergeWithTuning(Instance $instance, ?bool $metricsEnabled = null, string $mode = 'converge', ?Route $activating = null): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $metrics = $metricsEnabled ?? $this->serviceMetrics?->enabled($instance->node) ?? false;
        $applications = RouteWebRoot::servedApplications($instance, $activating);
        $configuration = $this->renderer->render($identity, $metrics, applications: $applications);
        $initialConfiguration = $this->renderer->render($identity, $metrics, initialRelease: true, applications: $applications);
        if ($mode !== 'monitor') {
            ($this->packages ?? new RemotePhpPackageManager(agents: $this->agents))->installForProductionRuntime($instance->node->loadMissing('roles'), collect([$identity->version]));
        }

        AgentStep::run($this->agents, $instance->node, 'php.converge-production-runtime', [
            'mode' => $mode,
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

    public function refreshCache(Instance $instance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($instance);

        try {
            AgentStep::run($this->agents, $instance->node, 'php.refresh-production-cache', [
                'user' => $identity->user,
                'home' => $identity->home,
                'version' => $identity->version,
                'marker' => $identity->marker(),
                'deadline_seconds' => $this->cacheDeadlineSeconds,
            ], 'app-prod-php-cache-refresh', 'app-prod.php_cache_refresh_failed', 'App production');
        } catch (RuntimeConvergenceException $exception) {
            $known = self::CACHE_ERRORS[AgentStep::refusal($exception)->errorCode ?? ''] ?? null;

            if ($known === null) {
                throw $exception;
            }

            throw new RuntimeConvergenceException('app-prod-php-cache-refresh', $known[0], $known[1], $exception, $exception->result);
        }
    }
}
