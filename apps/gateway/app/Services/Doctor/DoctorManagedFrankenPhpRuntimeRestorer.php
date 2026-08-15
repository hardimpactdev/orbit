<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class DoctorManagedFrankenPhpRuntimeRestorer
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private WorkspaceRuntimeContainerRenderer $workspaceRuntimeContainerRenderer,
        private EnsureAppProcessRuntimeUnits $ensureAppProcessRuntimeUnits,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private DoctorFrankenPhpRuntimeContainerManagers $runtimeContainerManagers,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function restoreMissingProcess(Node $node, string $key, array $detail): ?array
    {
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $instanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if ($appName === null || $instanceName === null) {
            return null;
        }

        $app = App::query()
            ->with('instances')
            ->where('name', $appName)
            ->first();
        $instance = $app instanceof App
            ? $app->instances->firstWhere('name', $instanceName)
            : null;

        if (
            ! $app instanceof App
            || ! $instance instanceof Instance
            || $this->workspacePlacement->nodeForInstance($instance)?->id !== $node->id
        ) {
            return null;
        }

        try {
            $process = $this->ensureFrankenPhpRuntimeProcess->forApp($app, $instance);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return $this->restoreAppRuntime($node, $key, $process, $app);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restoreManagedRuntime(Node $node, string $key, Process $process): ?array
    {
        $process->loadMissing('owner');

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? trim($config['container_spec_hash_label'])
            : '';

        if ($hashLabel === '') {
            return null;
        }

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
            return $this->restoreAppRuntime($node, $key, $process, $process->owner);
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            return $this->restoreWorkspaceRuntime($node, $key, $process, $process->owner);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restoreAppProcessRuntimeUnits(
        Node $node,
        string $key,
        Process $process,
        App $app,
    ): ?array {
        $process->loadMissing('instance');
        $instance = $process->instance;

        if (! $instance instanceof Instance) {
            return null;
        }

        try {
            $this->refreshProcessIntent($process);
            $warnings = $this->ensureAppProcessRuntimeUnits->handle($app, $instance);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        if ($warnings !== []) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Process runtime restore for {$app->name}.{$instance->name} completed with warnings.",
                'details' => [
                    'warnings' => $warnings,
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored process runtime units for {$app->name}.{$instance->name}.",
            'details' => [
                'app' => $app->name,
                'instance' => $instance->name,
                'process' => $process->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreAppRuntime(Node $node, string $key, Process $process, App $app): array
    {
        $process->loadMissing('instance');
        $instance = $process->instance;
        $instanceNode = $instance instanceof Instance
            ? $this->workspacePlacement->nodeForInstance($instance)
            : null;

        if (
            ! $instance instanceof Instance
            || $instance->app_id !== $app->id
            || ! $instanceNode instanceof Node
            || $instanceNode->id !== $node->id
        ) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance?->name,
                    'process' => $process->name,
                    'error' => 'Process instance has no active serving node.',
                ],
            ];
        }

        try {
            $this->ensureFrankenPhpRuntimeProcess->forApp($app, $instance);
            $this->ensureAppRuntimeTlsMaterial($app, $instance, $instanceNode);

            $container = $this->appRuntimeContainerRenderer->renderForInstance($app, $instance);
            $outcome = $this->runtimeContainerManagers->forApp()->apply($instanceNode, $container);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'process' => $process->name,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for {$app->name}.{$instance->name}.",
            'details' => [
                'app' => $app->name,
                'instance' => $instance->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreWorkspaceRuntime(
        Node $node,
        string $key,
        Process $process,
        Workspace $workspace,
    ): array {
        $workspace->loadMissing(['app.node', 'instance']);
        $app = $workspace->app;
        $workspaceNode = $this->workspacePlacement->nodeForWorkspace($workspace);

        if (! $app instanceof App || ! $workspaceNode instanceof Node || $workspaceNode->id !== $node->id) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => 'Process workspace has no active parent app node.',
                ],
            ];
        }

        try {
            $this->ensureFrankenPhpRuntimeProcess->forWorkspace($workspace);
            $this->ensureWorkspaceRuntimeTlsMaterial($workspace, $workspaceNode);

            $container = $this->workspaceRuntimeContainerRenderer->render($workspace);
            $outcome = $this->runtimeContainerManagers->forWorkspace()->apply($workspaceNode, $container);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $workspaceNode instanceof Node ? $workspaceNode->name : $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $workspaceNode->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for workspace {$workspace->name}.",
            'details' => [
                'app' => $app->name,
                'workspace' => $workspace->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function refreshProcessIntent(Process $process): void
    {
        $process->loadMissing(['owner', 'instance']);

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? $config['container_spec_hash_label']
            : null;

        if ($hashLabel === null || trim($hashLabel) === '') {
            return;
        }

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
            if (! $process->instance instanceof Instance) {
                throw new RuntimeException(
                    'A concrete instance is required to refresh FrankenPHP process intent.',
                );
            }

            $this->ensureFrankenPhpRuntimeProcess->forApp($process->owner, $process->instance);

            return;
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            $this->ensureFrankenPhpRuntimeProcess->forWorkspace($process->owner);
        }
    }

    private function ensureAppRuntimeTlsMaterial(App $app, ?Instance $instance, Node $node): void
    {
        if (! $this->innerTlsPolicy->appliesToApp($app, $instance)) {
            return;
        }

        $this->siteCertificateInstaller->ensureFor(
            $node,
            $this->innerTlsPolicy->appRouteDomain($app, $instance),
        );
    }

    private function ensureWorkspaceRuntimeTlsMaterial(Workspace $workspace, Node $node): void
    {
        if (! $this->innerTlsPolicy->appliesToWorkspace($workspace)) {
            return;
        }

        $this->siteCertificateInstaller->ensureFor(
            $node,
            $this->innerTlsPolicy->workspaceRouteDomain($workspace),
        );
    }
}
