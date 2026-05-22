<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;

final readonly class WorkspaceRuntimeContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
    ) {}

    public function render(Workspace $workspace, ?string $preloadPath = null): WorkspaceRuntimeContainer
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new InvalidArgumentException("Workspace '{$workspace->name}' has no owning app; cannot render runtime container.");
        }

        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' belongs to app '{$app->name}' with runtime kind '{$app->runtime_kind->value}' and does not get a FrankenPHP runtime container.",
            );
        }

        $phpVersion = $workspace->effectivePhpVersion();

        if (! is_string($phpVersion) || trim($phpVersion) === '') {
            throw new InvalidArgumentException("Workspace '{$workspace->name}' has no resolvable PHP version; cannot render runtime container.");
        }

        $policy = $this->phpRuntimePolicy->forVersion($phpVersion, $preloadPath);
        $sourcePath = rtrim((string) $workspace->path, '/');

        if ($sourcePath === '') {
            throw new InvalidArgumentException("Workspace '{$workspace->name}' has no source path; cannot render runtime container.");
        }

        return new WorkspaceRuntimeContainer(
            name: $this->containerName($workspace),
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            appSlug: $app->name,
            workspaceSlug: $workspace->name,
            environment: $this->environmentFor($app, $workspace, $phpVersion),
            mounts: [
                [
                    'source' => $sourcePath,
                    'target' => WorkspaceRuntimeContainer::SourceTarget,
                    'read_only' => false,
                ],
                [
                    'source' => $this->phpIniHostPath($workspace),
                    'target' => WorkspaceRuntimeContainer::PhpIniMountTarget,
                    'read_only' => true,
                ],
            ],
            networkAliases: [
                $this->containerName($workspace),
                "ws-{$app->name}-{$workspace->name}",
            ],
            phpIni: $policy->phpIni,
        );
    }

    public function containerName(Workspace $workspace): string
    {
        $workspace->loadMissing('app');
        $appSlug = $workspace->app?->name ?? 'app';

        return "orbit-ws-{$appSlug}-{$workspace->name}";
    }

    public function phpIniHostPath(Workspace $workspace): string
    {
        return $this->runtimeConfigPath($workspace);
    }

    public function runtimeConfigPath(Workspace $workspace): string
    {
        $workspace->loadMissing('app');
        $appSlug = $workspace->app?->name ?? 'app';

        return "/etc/orbit/workspaces/{$appSlug}-{$workspace->name}.ini";
    }

    public function upstreamUrl(Workspace $workspace): string
    {
        return 'http://'.$this->containerName($workspace);
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app, Workspace $workspace, string $phpVersion): array
    {
        return [
            // FrankenPHP-consumed envs: SERVER_NAME sets the Caddy listener
            // address; SERVER_ROOT sets the document root served inside the
            // container. The workspace inherits the app's document_root so the
            // served URL boundary matches the owning app.
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => $this->documentRootInContainer($app),
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_DOCUMENT_ROOT' => $app->document_root,
            'ORBIT_WORKSPACE' => $workspace->name,
            'ORBIT_PHP_VERSION' => $phpVersion,
        ];
    }

    public function documentRootInContainer(App $app): string
    {
        $documentRoot = trim((string) $app->document_root, '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return WorkspaceRuntimeContainer::SourceTarget;
        }

        return WorkspaceRuntimeContainer::SourceTarget.'/'.$documentRoot;
    }
}
