<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\RemoteAppSourcePathProbe;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessDockerRuntimeManager;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class ActiveReleaseRuntimeActivator
{
    public function __construct(
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
        private RemoteAppSourcePathProbe $appSourcePathProbe,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private ProcessDockerRuntimeManager $processDockerRuntimeManager,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{live_path: string, resolved_path: string}
     */
    public function activate(Project $app, AppInstance $instance, array $context): array
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
                    'project' => $app->name,
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
    private function activateRuntime(Project $app, AppInstance $instance, array $context): array
    {
        $node = $app->node;
        $appPath = $context['app_path'] ?? null;
        $livePath = $context['live_path'] ?? null;

        if (
            ! $node instanceof Node
            || ! is_string($appPath)
            || trim($appPath) === ''
            || ! is_string($livePath)
            || trim($livePath) === ''
        ) {
            throw new GatewayApiException(
                message: "The active release path for '{$this->targetName($instance)}' is unavailable.",
                errorCode: 'deploy.active_release_missing',
                errorMeta: [
                    'project' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $probe = $this->appSourcePathProbe->inspect($node, $livePath, $appPath);

        if (! $probe['exists'] || $probe['resolved_path'] === null) {
            throw new GatewayApiException(
                message: "The active release for '{$this->targetName($instance)}' does not exist.",
                errorCode: 'deploy.active_release_missing',
                errorMeta: [
                    'project' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        if (! $probe['within_boundary']) {
            throw new GatewayApiException(
                message: "The active release for '{$this->targetName($instance)}' resolves outside its app boundary.",
                errorCode: 'deploy.active_release_unsafe',
                errorMeta: [
                    'project' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $this->persistActiveDocumentRoot($instance);
        $instance->refresh();

        $container = $this->appRuntimeContainerRenderer->renderForInstance($instance->app, $instance);
        $this->ensureFrankenPhpRuntimeProcess->forApp($instance->app, $instance);
        $outcome = $this->appRuntimeContainerManager->apply($node, $container);

        if (
            $outcome === AppRuntimeContainerApplyOutcome::Unchanged
            && ! $this->processDockerRuntimeManager->restart($node, $container->name())
        ) {
            throw new GatewayApiException(
                message: "The active release runtime for '{$this->targetName($instance)}' could not be restarted.",
                errorCode: 'deploy.runtime_restart_failed',
                errorMeta: [
                    'project' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        return [
            'live_path' => $livePath,
            'resolved_path' => $probe['resolved_path'],
        ];
    }

    private function persistActiveDocumentRoot(AppInstance $instance): void
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
            throw new GatewayApiException(
                message: "Instance '{$this->targetName($instance)}' does not support active releases.",
                errorCode: 'deploy.instance_driver_unsupported',
                errorMeta: [
                    'project' => $instance->app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        $instance->driver_config = new OrbitAppInstanceDriverConfigData(
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

    private function targetName(AppInstance $instance): string
    {
        $instance->loadMissing('app');

        return "{$instance->app->name}.{$instance->name}";
    }
}
