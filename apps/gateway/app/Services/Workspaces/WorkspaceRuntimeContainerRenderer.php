<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppDevelopmentPackagesMount;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeMountService;
use App\Services\Apps\FrankenPhpRuntimeConfigRenderer;
use App\Services\Apps\RuntimeClientTrustPolicy;
use App\Services\Apps\RuntimeHostRoutingPolicy;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class WorkspaceRuntimeContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
        private AppDevelopmentPackagesMount $appDevelopmentPackagesMount = new AppDevelopmentPackagesMount,
        private AppRuntimeMountService $appRuntimeMounts = new AppRuntimeMountService(new WorkspacePlacement),
        private FrankenPhpRuntimeConfigRenderer $frankenPhpConfig = new FrankenPhpRuntimeConfigRenderer,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private RuntimeClientTrustPolicy $runtimeClientTrust = new RuntimeClientTrustPolicy,
        private RuntimeHostRoutingPolicy $runtimeHostRouting = new RuntimeHostRoutingPolicy,
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private ?WorkspaceRoleGuard $roleGuard = null,
    ) {}

    public function render(Workspace $workspace, ?string $preloadPath = null): WorkspaceRuntimeContainer
    {
        $this->roleGuard()->ensureWorkspaceSupported($workspace);
        $workspace->loadMissing('app');
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' has no owning app; cannot render runtime container.",
            );
        }

        $runtime = $app->runtimeKind();

        if ($runtime !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' belongs to app '{$app->name}' with runtime kind '{$runtime->value}' and does not get a FrankenPHP runtime container.",
            );
        }

        $phpVersion = $workspace->effectivePhpVersion();

        if (! is_string($phpVersion) || trim($phpVersion) === '') {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' has no resolvable PHP version; cannot render runtime container.",
            );
        }

        $policy = $this->phpRuntimePolicy->forVersion($phpVersion, $preloadPath);
        $sourcePath = rtrim($workspace->path, '/');

        if ($sourcePath === '') {
            throw new InvalidArgumentException(
                "Workspace '{$workspace->name}' has no source path; cannot render runtime container.",
            );
        }

        $mounts = [
            [
                'source' => $sourcePath,
                'target' => WorkspaceRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
            [
                'source' => $sourcePath,
                'target' => $sourcePath,
                'read_only' => false,
            ],
            [
                'source' => $this->phpIniHostPath($workspace),
                'target' => WorkspaceRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
        ];

        $node = $this->placement->nodeForWorkspace($workspace);

        if ($node instanceof Node && ($packagesMount = $this->appDevelopmentPackagesMount->forNode($node)) !== null) {
            $mounts[] = $packagesMount;
        }

        $workspace->loadMissing('instance.runtimeMounts');
        $instance = $this->placement->instanceForWorkspace($workspace);

        foreach ($this->appRuntimeMounts->mountsForRuntime($app, $instance) as $mount) {
            $mounts[] = $mount;
        }

        if ($this->innerTlsPolicy->appliesToWorkspace($workspace) && $node !== null) {
            foreach ($this->innerTlsPolicy->runtimeTlsMounts(
                $node,
                $this->innerTlsPolicy->workspaceRouteDomain($workspace),
            ) as $mount) {
                $mounts[] = $mount;
            }
        }

        $mounts = array_merge($mounts, $this->runtimeClientTrust->mountsForWorkspace($workspace));

        return new WorkspaceRuntimeContainer(
            name: $this->containerName($workspace),
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: $node?->hasActiveRole('app-dev') === true ? 'unless-stopped' : 'always',
            appSlug: $app->name,
            workspaceSlug: $workspace->name,
            environment: array_merge(
                $this->environmentFor($app, $workspace, $phpVersion),
                $this->runtimeClientTrust->environmentForWorkspace($workspace),
            ),
            mounts: $mounts,
            networkAliases: [
                $this->containerName($workspace),
                "ws-{$app->name}-{$workspace->name}",
            ],
            phpIni: array_merge(
                $policy->phpIni,
                $this->runtimeClientTrust->phpIniForWorkspace($workspace),
            ),
            extraHosts: $this->runtimeHostRouting->forWorkspace($workspace),
        );
    }

    public function containerName(Workspace $workspace): string
    {
        $workspace->loadMissing('app');
        $appSlug = $workspace->app?->name;

        return "orbit-ws-{$appSlug}-{$workspace->name}";
    }

    public function phpIniHostPath(Workspace $workspace): string
    {
        return $this->runtimeConfigPath($workspace);
    }

    public function runtimeConfigPath(Workspace $workspace): string
    {
        $this->roleGuard()->ensureWorkspaceSupported($workspace);
        $workspace->loadMissing(['app.instances', 'instance']);
        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $app instanceof App || ! $node instanceof Node) {
            throw new RuntimeException(
                "Workspace '{$workspace->name}' has no owning app node; cannot render runtime config path.",
            );
        }

        return $this->nodeHostPaths->workspaceRuntimeConfigPath($node, $app->name, $workspace->name);
    }

    public function upstreamUrl(Workspace $workspace): string
    {
        $this->roleGuard()->ensureWorkspaceSupported($workspace);

        if ($this->innerTlsPolicy->appliesToWorkspace($workspace)) {
            return 'https://'.$this->containerName($workspace).':'.AppDevelopmentInnerTlsPolicy::InternalTlsPort;
        }

        return 'http://'.$this->containerName($workspace);
    }

    private function roleGuard(): WorkspaceRoleGuard
    {
        return $this->roleGuard ?? app(WorkspaceRoleGuard::class);
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app, Workspace $workspace, string $phpVersion): array
    {
        $documentRoot = $this->placement->documentRootForWorkspace($workspace);
        $environment = [
            'SERVER_ROOT' => $this->documentRootInContainer($documentRoot),
            'XDG_CONFIG_HOME' => AppRuntimeContainerRenderer::XdgConfigHome,
            'XDG_DATA_HOME' => AppRuntimeContainerRenderer::XdgDataHome,
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_DOCUMENT_ROOT' => $documentRoot,
            'ORBIT_WORKSPACE' => $workspace->name,
            'ORBIT_PHP_VERSION' => $phpVersion,
        ];

        if ($this->innerTlsPolicy->appliesToWorkspace($workspace)) {
            $environment = array_merge(
                $environment,
                $this->innerTlsPolicy->runtimeTlsEnvironment($this->innerTlsPolicy->workspaceRouteDomain($workspace)),
            );
        } else {
            $environment['SERVER_NAME'] = ':80';
        }

        $frankenPhpConfig = $this->frankenPhpConfig->classic($app);

        if ($frankenPhpConfig !== null) {
            $environment['FRANKENPHP_CONFIG'] = $frankenPhpConfig;
        }

        return $environment;
    }

    public function documentRootInContainer(string $documentRoot): string
    {
        $documentRoot = trim($documentRoot, '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return WorkspaceRuntimeContainer::SourceTarget;
        }

        return WorkspaceRuntimeContainer::SourceTarget.'/'.$documentRoot;
    }
}
