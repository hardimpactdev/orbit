<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Container\Container;
use Throwable;

final readonly class WorkspaceSetupTargetResolver
{
    public function __construct(
        private AppAgentIdeDefaults $appAgentIdeDefaults,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $roleGuard,
        private Container $container,
    ) {}

    /**
     * @return array{Workspace, App, Node, bool}
     */
    public function resolve(
        ?string $name,
        ?string $appName,
        ?string $path,
        ?string $callerCwd = null,
        ?Node $callerNode = null,
    ): array {
        if ($path !== null) {
            return $this->resolveByPath($path, $appName, $name, $callerNode);
        }

        if ($name !== null && $appName !== null) {
            return $this->resolveByName($name, $appName);
        }

        $cwd = $this->normalizePath($callerCwd ?? (string) getcwd());
        $outcome = $this->pathOwnership($cwd);
        $workspace = $outcome['workspace'] ?? null;
        $ownedApp = $outcome['app'] ?? null;

        if ($outcome['type'] === 'workspace' && $workspace instanceof Workspace) {
            $this->assertExplicitMatches($workspace, $appName, null);

            return $this->unwrap($workspace, false);
        }

        if ($outcome['type'] === 'app_root' && $ownedApp instanceof App) {
            $app = $ownedApp;
            $instance = $outcome['instance'] ?? null;
            $label = $instance instanceof AppInstance ? $this->selectionLabel($app, $instance) : $app->name;

            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_is_app_root',
                "The current directory is the '{$label}' app root, not a workspace path. Use 'orbit workspace:new' to create a workspace, or change into an existing workspace path and rerun 'orbit workspace:setup'.",
                [
                    'app' => $label,
                    'path' => $cwd,
                    'next_command' => 'orbit workspace:new',
                ],
            );
        }

        $apps =
            $outcome['type'] === 'inside_app' && $ownedApp instanceof App
                ? [$ownedApp]
                : $this->appsForCaller($callerNode);

        $resolved = $this->probeAdapters($cwd, $apps);

        if ($resolved !== null) {
            [$adapter, $resolution] = $resolved;
            $this->assertAdapterMatchesExplicitInput($resolution, $name, $appName);

            return $this->resolveAdapterWorkspace($adapter, $resolution);
        }

        if ($name !== null) {
            return $this->resolveByName($name, $appName);
        }

        throw new WorkspaceSetupResolutionFailed(
            'validation_failed',
            'Workspace name is required when the current directory cannot resolve a workspace.',
            ['field' => 'name', 'reason' => 'missing_required_input'],
        );
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveByPath(
        string $path,
        ?string $appName,
        ?string $name = null,
        ?Node $callerNode = null,
    ): array {
        try {
            $selection = $this->appSelectorResolver->resolveRequired($appName);
        } catch (AppSelectionResolutionFailed $exception) {
            throw new WorkspaceSetupResolutionFailed(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->meta,
            );
        }

        $app = $selection->app;
        $explicitInstance =
            $selection->instance ?? $this->placement->matchingOrbitInstanceForPath(
                $app,
                $path,
            ) ?? $this->callerNodeInstanceForPath($app, $callerNode, $path);

        if ($name === null) {
            $resolved = $this->probeAdapters($path, [$app]);

            if ($resolved !== null) {
                [$adapter, $resolution] = $resolved;
                $this->assertAdapterMatchesExplicitInput($resolution, null, $appName);

                return $this->resolveAdapterWorkspace($adapter, $resolution, $explicitInstance);
            }
        }

        $instance = $this->concreteInstance($app, $explicitInstance);
        $workspaceName = $name ?? basename($path);
        $existing = $this->firstWorkspaceMatch($app, $workspaceName, $instance);

        if (! $this->pathAllowedForWorkspace($app, $path, $instance)) {
            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_is_app_root',
                "Path {$path} is the '{$this->selectionLabel(
     $app,
     $instance,
 )}' app root, not a workspace path. Use 'orbit workspace:new' to create a workspace, or pass a workspace path with --path.",
                [
                    'app' => $this->selectionLabel($app, $instance),
                    'path' => $path,
                    'next_command' => 'orbit workspace:new',
                ],
            );
        }

        if ($existing instanceof Workspace) {
            $existing->update([
                'app_instance_id' => $instance->id,
                'path' => $path,
            ]);

            return $this->unwrap($existing->fresh(['app.node', 'app.instances', 'appInstance']), false);
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => $workspaceName,
            'path' => $path,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return $this->unwrap($workspace->load(['app.node', 'app.instances', 'appInstance']), true);
    }

    private function callerNodeInstanceForPath(App $app, ?Node $callerNode, string $path): ?AppInstance
    {
        if (! $callerNode instanceof Node) {
            return null;
        }

        $app->loadMissing('instances');

        $matches = $app
            ->instances
            ->filter(function (AppInstance $instance) use ($callerNode): bool {
                $node = $this->placement->nodeForInstance($instance);

                return $node instanceof Node && $node->is($callerNode);
            })
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $instance = $matches->first();

        return $instance instanceof AppInstance ? $instance : null;
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveByName(string $name, ?string $appName): array
    {
        $query = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('name', $name);

        $selection = null;

        if ($appName !== null) {
            try {
                $selection = $this->appSelectorResolver->requireInstance(
                    $this->appSelectorResolver->resolveRequired($appName),
                );
            } catch (AppSelectionResolutionFailed $exception) {
                throw new WorkspaceSetupResolutionFailed(
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->meta,
                );
            }

            $query->where('app_id', $selection->app->id);
        }

        $workspaces = $query->get();
        $workspace = $selection instanceof AppSelection
            ? $workspaces->first(
                fn (Workspace $workspace): bool => $this->appSelectorResolver->matchesWorkspace($workspace, $selection),
            )
            : $workspaces->first();

        if (! $workspace instanceof Workspace) {
            throw new WorkspaceSetupResolutionFailed('workspace.not_found', "Workspace '{$name}' not found.", [
                'field' => 'workspace',
            ]);
        }

        return $this->unwrap($workspace, false);
    }

    /**
     * @return array{type: 'workspace', workspace: Workspace}|array{type: 'app_root'|'inside_app', app: App, instance?: AppInstance}|array{type: 'unregistered'}
     */
    private function pathOwnership(string $cwd): array
    {
        /** @var list<Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->get()
            ->all();

        usort(
            $workspaces,
            fn (Workspace $first, Workspace $second): int => (
                strlen($this->normalizePath($second->path)) <=> strlen($this->normalizePath($first->path))
            ),
        );

        foreach ($workspaces as $workspace) {
            if (! $this->pathMatches($this->normalizePath($workspace->path), $cwd)) {
                continue;
            }

            if ($this->adapterConfirmsRegisteredWorkspace($workspace, $cwd)) {
                return ['type' => 'workspace', 'workspace' => $workspace];
            }
        }

        $instanceMatch = $this->appInstanceForPath($cwd);

        if ($instanceMatch !== null) {
            ['app' => $app, 'instance' => $instance, 'path' => $path] = $instanceMatch;

            return (
                $path === $cwd
                    ? ['type' => 'app_root', 'app' => $app, 'instance' => $instance]
                    : ['type' => 'inside_app', 'app' => $app, 'instance' => $instance]
            );
        }

        $app = App::query()
            ->with(['node', 'instances'])
            ->get()
            ->sortByDesc(fn (App $app): int => strlen($this->normalizePath($app->path)))
            ->first(fn (App $app): bool => $this->pathMatches($this->normalizePath($app->path), $cwd));

        if ($app instanceof App) {
            return (
                $this->normalizePath($app->path) === $cwd
                    ? ['type' => 'app_root', 'app' => $app]
                    : ['type' => 'inside_app', 'app' => $app]
            );
        }

        return ['type' => 'unregistered'];
    }

    private function adapterConfirmsRegisteredWorkspace(Workspace $workspace, string $cwd): bool
    {
        if ($workspace->agent_ide === null || $workspace->agent_ide === 'none') {
            return true;
        }

        $app = $workspace->app;

        if (! $app instanceof App) {
            return false;
        }

        try {
            $resolution = $this->pathResolver()->resolve($workspace->agent_ide, $app, $cwd);
        } catch (Throwable $exception) {
            throw new WorkspaceSetupResolutionFailed(
                'workspace.agent_ide_path_resolution_failed',
                "The '{$workspace->agent_ide}' adapter could not resolve the current directory to a managed workspace.",
                [
                    'adapter' => $workspace->agent_ide,
                    'path' => $cwd,
                    'reason' => $exception->getMessage() !== '' ? $exception->getMessage() : 'adapter_unreachable',
                ],
            );
        }

        if (! $resolution instanceof WorkspacePathResolution) {
            return false;
        }

        if ($resolution->appSlug !== $app->name || $resolution->workspaceName !== $workspace->name) {
            return false;
        }

        if ($this->normalizePath($resolution->path) !== $this->normalizePath($workspace->path)) {
            return false;
        }

        return (
            $workspace->agent_ide_workspace_id === null
            || $workspace->agent_ide_workspace_id === $resolution->adapterWorkspaceId
        );
    }

    /**
     * @param  list<App|mixed>  $apps
     * @return array{string, WorkspacePathResolution}|null
     */
    private function probeAdapters(string $cwd, array $apps): ?array
    {
        $matches = [];

        foreach ($apps as $app) {
            if (! $app instanceof App) {
                continue;
            }

            $adapter = $this->appAgentIdeDefaults->payloadFor($app)['effective_adapter'];

            if (! is_string($adapter) || $adapter === '') {
                continue;
            }

            try {
                $match = $this->pathResolver()->resolve($adapter, $app, $cwd);
            } catch (Throwable $exception) {
                throw new WorkspaceSetupResolutionFailed(
                    'workspace.agent_ide_path_resolution_failed',
                    "The '{$adapter}' adapter could not resolve the current directory to a managed workspace.",
                    [
                        'adapter' => $adapter,
                        'path' => $cwd,
                        'reason' => $exception->getMessage() !== '' ? $exception->getMessage() : 'adapter_unreachable',
                    ],
                );
            }

            if ($match instanceof WorkspacePathResolution) {
                $matches[$adapter] = $match;
            }
        }

        if (count($matches) > 1) {
            $adapters = array_keys($matches);
            sort($adapters);

            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'Multiple Agent IDE adapters resolved the current directory. Pass --app=<slug> to disambiguate.',
                ['field' => 'app', 'reason' => 'adapter_ambiguous', 'adapters' => $adapters],
            );
        }

        if ($matches === []) {
            return null;
        }

        $adapter = array_key_first($matches);

        return [$adapter, $matches[$adapter]];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveAdapterWorkspace(
        string $adapter,
        WorkspacePathResolution $resolution,
        ?AppInstance $explicitInstance = null,
    ): array {
        $app = $this->resolveApp($resolution->appSlug);

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'Adapter resolved an unknown parent app.', [
                'field' => 'app',
            ]);
        }

        $instance = $this->concreteInstance(
            $app,
            $explicitInstance ?? $this->placement->matchingOrbitInstanceForPath($app, $resolution->path),
        );
        $workspace = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('app_id', $app->id)
            ->where('name', $resolution->workspaceName)
            ->first();

        $isAdoption = ! $workspace instanceof Workspace;

        if ($workspace instanceof Workspace) {
            $workspace->update([
                'app_instance_id' => $instance->id,
                'path' => $resolution->path,
                'agent_ide' => $adapter,
                'agent_ide_workspace_id' => $resolution->adapterWorkspaceId,
            ]);

            $workspace = $workspace->fresh(['app.node', 'app.instances', 'appInstance']);

            if (! $workspace instanceof Workspace) {
                throw new WorkspaceSetupResolutionFailed(
                    'workspace.not_found',
                    'Workspace disappeared during setup resolution.',
                    [
                        'field' => 'workspace',
                    ],
                );
            }

            return $this->unwrap($workspace, false);
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => $resolution->workspaceName,
            'path' => $resolution->path,
            'agent_ide' => $adapter,
            'agent_ide_workspace_id' => $resolution->adapterWorkspaceId,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return $this->unwrap($workspace->load(['app.node', 'app.instances', 'appInstance']), $isAdoption);
    }

    /**
     * @return list<App>
     */
    private function appsForCaller(?Node $callerNode): array
    {
        $query = App::query()->with('node');

        if ($callerNode instanceof Node && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($callerNode)) {
            $query->where('node_id', $callerNode->id);
        }

        return $query->get()->all();
    }

    private function assertExplicitMatches(Workspace $workspace, ?string $appName, ?string $path): void
    {
        $selection = null;

        if ($appName !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($appName);
            } catch (AppSelectionResolutionFailed $exception) {
                throw new WorkspaceSetupResolutionFailed(
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->meta,
                );
            }
        }

        if (
            $selection instanceof AppSelection
            && ! $this->appSelectorResolver->matchesWorkspace($workspace, $selection)
        ) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'The --app value does not match the workspace resolved from the current directory.',
                ['field' => 'app'],
            );
        }

        if ($path !== null && $this->normalizePath($workspace->path) !== $this->normalizePath($path)) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'The --path value does not match the workspace resolved from the current directory.',
                ['field' => 'path'],
            );
        }
    }

    private function assertAdapterMatchesExplicitInput(
        WorkspacePathResolution $resolution,
        ?string $name,
        ?string $appName,
    ): void {
        if ($name !== null && $name !== $resolution->workspaceName) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'The workspace name does not match the Agent IDE adapter resolution.',
                ['field' => 'name', 'reason' => 'adapter_mismatch'],
            );
        }

        if ($appName !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($appName);
            } catch (AppSelectionResolutionFailed $exception) {
                throw new WorkspaceSetupResolutionFailed(
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->meta,
                );
            }

            if ($selection->app->name === $resolution->appSlug) {
                return;
            }

            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'The --app value does not match the Agent IDE adapter resolution.',
                ['field' => 'app', 'reason' => 'adapter_mismatch'],
            );
        }
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function unwrap(Workspace $workspace, bool $isAdoption): array
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "App not found for workspace '{$workspace->name}'.",
                ['field' => 'app'],
            );
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "Node not found for workspace '{$workspace->name}'.",
                ['field' => 'app'],
            );
        }

        try {
            $this->roleGuard->ensureNodeSupportsWorkspaces($app, $node);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            throw new WorkspaceSetupResolutionFailed(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->meta,
            );
        }

        return [$workspace, $app, $node, $isAdoption];
    }

    private function resolveApp(?string $appName): ?App
    {
        if ($appName === null) {
            return null;
        }

        $selection = $this->appSelectorResolver->resolve($appName);

        return $selection?->app;
    }

    private function concreteInstance(App $app, ?AppInstance $instance): AppInstance
    {
        if ($instance instanceof AppInstance) {
            return $instance;
        }

        try {
            $selection = $this->appSelectorResolver->requireInstance(new AppSelection(app: $app));
        } catch (AppSelectionResolutionFailed $exception) {
            throw new WorkspaceSetupResolutionFailed(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->meta,
            );
        }

        $resolved = $selection->instance;

        if (! $resolved instanceof AppInstance) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "App '{$app->name}' has no concrete app instance.",
                ['field' => 'app', 'reason' => 'app_instance_required'],
            );
        }

        return $resolved;
    }

    private function pathAllowedForWorkspace(App $app, string $path, AppInstance $instance): bool
    {
        $appPath = $this->instancePath($instance) ?? $app->path;

        $appPath = rtrim($this->normalizePath($appPath), '/');

        return $this->normalizePath($path) !== $appPath;
    }

    private function firstWorkspaceMatch(App $app, string $workspaceName, AppInstance $instance): ?Workspace
    {
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->get();

        return $workspaces->first(
            fn (Workspace $workspace): bool => $workspace->app_instance_id === $instance->id,
        );
    }

    /**
     * @return array{app: App, instance: AppInstance, path: string}|null
     */
    private function appInstanceForPath(string $cwd): ?array
    {
        /** @var list<array{app: App, instance: AppInstance, path: string}> $candidates */
        $candidates = [];

        /** @var list<App> $apps */
        $apps = App::query()
            ->with(['node', 'instances'])
            ->get()
            ->all();

        foreach ($apps as $app) {
            foreach ($app->instances as $instance) {
                $path = $this->instancePath($instance);

                if ($path === null || $path === '') {
                    continue;
                }

                $candidates[] = [
                    'app' => $app,
                    'instance' => $instance,
                    'path' => $path,
                ];
            }
        }

        usort(
            $candidates,
            fn (array $first, array $second): int => (
                strlen($this->normalizePath($second['path'])) <=> strlen($this->normalizePath($first['path']))
            ),
        );

        foreach ($candidates as $candidate) {
            $path = $this->normalizePath($candidate['path']);

            if (! $this->pathMatches($path, $cwd)) {
                continue;
            }

            return [
                'app' => $candidate['app'],
                'instance' => $candidate['instance'],
                'path' => $path,
            ];
        }

        return null;
    }

    private function instancePath(AppInstance $instance): ?string
    {
        $config = $instance->driver_config;

        if ($config instanceof OrbitAppInstanceDriverConfigData && is_string($config->path) && $config->path !== '') {
            return $config->path;
        }

        return null;
    }

    private function selectionLabel(App $app, ?AppInstance $instance): string
    {
        return $instance instanceof AppInstance ? "{$app->name}.{$instance->name}" : $app->name;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/') ?: '/';
    }

    private function pathMatches(string $candidate, string $cwd): bool
    {
        return $candidate === $cwd || str_starts_with($cwd, "{$candidate}/");
    }

    private function pathResolver(): AgentIdeWorkspacePathResolver
    {
        return $this->container->make(AgentIdeWorkspacePathResolver::class);
    }
}
