<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use InvalidArgumentException;
use RuntimeException;

final readonly class EnsureFrankenPhpRuntimeProcess
{
    private const string Command = 'frankenphp';

    public function __construct(
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private WorkspaceRuntimeContainerRenderer $workspaceRuntimeContainerRenderer,
        private WorkspacePlacement $placement,
    ) {}

    public function forApp(Project $app, ?AppInstance $appInstance = null): Process
    {
        $app->loadMissing('instances');
        $appInstance ??= $this->soleInstance($app);
        $node = $this->placement->nodeForInstance($appInstance);

        if (! $node instanceof Node || $appInstance->app_id !== $app->id) {
            throw new InvalidArgumentException(
                "Instance '{$app->name}.{$appInstance->name}' has no valid app owner placement.",
            );
        }

        $runtimeApp = $this->appRuntimeContainerRenderer->runtimeAppForInstance($app, $appInstance);
        $container = $this->appRuntimeContainerRenderer->renderForInstance($app, $appInstance);

        return $app->processes()->updateOrCreate(
            [
                'app_instance_id' => $appInstance->id,
                'name' => $this->appProcessName($app),
            ],
            [
                'node_id' => $node->id,
                'command' => self::Command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => ProcessRuntime::Docker,
                'tool' => null,
                'runtime_config' => [
                    'container_name' => $container->name(),
                    'container_spec_hash' => $container->specHash(),
                    'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                    'document_root' => $runtimeApp->document_root,
                    'php_ini_path' => $this->appRuntimeContainerRenderer->phpIniHostPathForInstance(
                        $app,
                        $appInstance,
                    ),
                    'php_version' => $runtimeApp->php_version,
                    'source_path' => $runtimeApp->path,
                ],
                'sort_order' => 0,
            ],
        );
    }

    public function forWorkspace(Workspace $workspace): Process
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        if (! $app instanceof Project) {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' has no owning app; cannot ensure FrankenPHP runtime process.",
            );
        }

        $container = $this->workspaceRuntimeContainerRenderer->render($workspace);
        $node = $this->placement->nodeForWorkspace($workspace);

        return $workspace->processes()->updateOrCreate(
            [
                'app_instance_id' => $workspace->app_instance_id,
                'name' => $this->workspaceProcessName($workspace),
            ],
            [
                'node_id' => $node?->id ?? $app->node_id,
                'command' => self::Command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => ProcessRuntime::Docker,
                'tool' => null,
                'runtime_config' => [
                    'container_name' => $container->name(),
                    'container_spec_hash' => $container->specHash(),
                    'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                    'document_root' => $this->placement->documentRootForWorkspace($workspace),
                    'php_ini_path' => $this->workspaceRuntimeContainerRenderer->phpIniHostPath($workspace),
                    'php_version' => $workspace->effectivePhpVersion(),
                    'source_path' => $workspace->path,
                ],
                'sort_order' => 0,
            ],
        );
    }

    public function appProcessName(Project $app): string
    {
        return "frankenphp-{$app->name}";
    }

    public function workspaceProcessName(Workspace $workspace): string
    {
        $workspace->loadMissing('app');

        if (! $workspace->app instanceof Project) {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' has no owning app; cannot name FrankenPHP runtime process.",
            );
        }

        return "frankenphp-{$workspace->app->name}-{$workspace->name}";
    }

    private function soleInstance(Project $app): AppInstance
    {
        $instances = $app->instances->values();
        $instance = $instances->first();

        if ($instances->count() === 1 && $instance instanceof AppInstance) {
            return $instance;
        }

        throw new RuntimeException("App '{$app->name}' requires one concrete instance for runtime seeding.");
    }
}
