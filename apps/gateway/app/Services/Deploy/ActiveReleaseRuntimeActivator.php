<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\ConvergesAppRuntimeContainers;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\RemoteAppSourcePathProbe;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ActiveReleaseRuntimeActivator
{
    public function __construct(
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private ConvergesAppRuntimeContainers $appRuntimeContainerManager,
        private RemoteAppSourcePathProbe $appSourcePathProbe,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private ProcessDockerRuntimeManager $processDockerRuntimeManager,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{live_path: string, resolved_path: string}
     */
    public function activate(App $app, Instance $instance, array $context): array
    {
        try {
            return $this->activateRuntime($app, $instance, $context);
        } catch (GatewayApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GatewayApiException(
                message: "The active release runtime for '{$this->targetName($instance)}' could not be activated.",
                errorCode: 'deploy.runtime_activation_failed',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'failure' => $exception::class,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{live_path: string, resolved_path: string}
     */
    private function activateRuntime(App $app, Instance $instance, array $context): array
    {
        $node = $this->placement->runtimeNode($app, $instance);

        if (
            ! $node instanceof Node
            || ! array_key_exists('app_path', $context)
            || ! is_string($context['app_path'])
            || trim($context['app_path']) === ''
            || ! array_key_exists('live_path', $context)
            || ! is_string($context['live_path'])
            || trim($context['live_path']) === ''
        ) {
            throw new GatewayApiException(
                message: "The active release path for '{$this->targetName($instance)}' is unavailable.",
                errorCode: 'deploy.active_release_missing',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $appPath = $context['app_path'];
        $livePath = $context['live_path'];
        $probe = $this->appSourcePathProbe->inspect($node, $livePath, $appPath);

        if (! $probe['exists'] || $probe['resolved_path'] === null) {
            throw new GatewayApiException(
                message: "The active release for '{$this->targetName($instance)}' does not exist.",
                errorCode: 'deploy.active_release_missing',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        if (! $probe['within_boundary']) {
            throw new GatewayApiException(
                message: "The active release for '{$this->targetName($instance)}' resolves outside its app boundary.",
                errorCode: 'deploy.active_release_unsafe',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $this->persistActiveDocumentRoot($instance);
        $instance->refresh();

        $container = $this->appRuntimeContainerRenderer->renderForInstance($instance->app, $instance);
        $this->ensureFrankenPhpRuntimeProcess->forApp($instance->app, $instance);
        $outcome = $this->appRuntimeContainerManager->apply($node, $container);

        // Deploy active-release FrankenPHP container restart only. This is not a
        // configured process lifecycle path (process:restart / process:update
        // --restart): the container is deploy infrastructure, not a process
        // definition unit, so durable process_events are intentionally not
        // recorded here. Failures surface as deploy.runtime_restart_failed.
        // Explicit exclusion: not a configured process lifecycle path.
        if (
            $outcome === AppRuntimeContainerApplyOutcome::Unchanged
            && ! $this->processDockerRuntimeManager->restart($node, $container->name())
        ) {
            throw new GatewayApiException(
                message: "The active release runtime for '{$this->targetName($instance)}' could not be restarted.",
                errorCode: 'deploy.runtime_restart_failed',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        return [
            'live_path' => $livePath,
            'resolved_path' => $probe['resolved_path'],
        ];
    }

    private function persistActiveDocumentRoot(Instance $instance): void
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
            throw new GatewayApiException(
                message: "Instance '{$this->targetName($instance)}' does not support active releases.",
                errorCode: 'deploy.instance_driver_unsupported',
                errorMeta: [
                    'app' => $instance->app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $instance->driver_config = new OrbitInstanceDriverConfigData(
            node_id: $config->node_id,
            node: $config->node,
            path: $config->path,
            document_root: $this->activeDocumentRoot($config->document_root),
            domain: $config->domain,
        );
        $instance->save();
    }

    private function activeDocumentRoot(?string $documentRoot): string
    {
        $documentRoot = trim((string) $documentRoot, characters: '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return 'live';
        }

        if ($documentRoot === 'live' || str_starts_with($documentRoot, 'live/')) {
            return $documentRoot;
        }

        return "live/{$documentRoot}";
    }

    private function targetName(Instance $instance): string
    {
        $instance->loadMissing('app');

        return "{$instance->app->name}.{$instance->name}";
    }
}
