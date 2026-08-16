<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;

final readonly class WorkspaceSetupTargetResolver
{
    public function __construct(
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $roleGuard,
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
        $ownedInstance = $outcome['instance'] ?? null;

        if ($outcome['type'] === 'workspace' && $workspace instanceof Workspace) {
            $this->assertExplicitMatches($workspace, $appName, null);

            return $this->unwrap($workspace, false);
        }

        if ($outcome['type'] === 'app_root' && $ownedApp instanceof App) {
            $app = $ownedApp;
            $instance = $outcome['instance'] ?? null;
            $label = $instance instanceof Instance ? $this->selectionLabel($app, $instance) : $app->name;

            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_is_project_root',
                "The current directory is the '{$label}' project root, not a workspace path. Use 'orbit workspace:new' to create a workspace, or change into an existing workspace path and rerun 'orbit workspace:setup'.",
                [
                    'instance' => $label,
                    'path' => $cwd,
                    'next_command' => 'orbit workspace:new',
                ],
            );
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
        $instance = $this->concreteInstance($app, $explicitInstance);
        $this->ensureInstanceSupportsWorkspaces($app, $instance);

        $workspaceName = $name ?? basename($path);
        $existing = $this->firstWorkspaceMatch($app, $workspaceName, $instance);

        if (! $this->pathAllowedForWorkspace($path, $instance)) {
            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_is_project_root',
                "Path {$path} is the '{$this->selectionLabel(
     $app,
     $instance,
 )}' project root, not a workspace path. Use 'orbit workspace:new' to create a workspace, or pass a workspace path with --path.",
                [
                    'instance' => $this->selectionLabel($app, $instance),
                    'path' => $path,
                    'next_command' => 'orbit workspace:new',
                ],
            );
        }

        if ($existing instanceof Workspace) {
            $existing->update([
                'instance_id' => $instance->id,
                'path' => $path,
            ]);

            return $this->unwrap($existing->fresh(['app.instances', 'instance']), false);
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => $workspaceName,
            'path' => $path,
            // Adoption creates this row after the snapshot migration, so an
            // empty value would be a brand-new live-inheriting workspace, not a
            // legacy one: the owning instance could later move it.
            'php_version' => $instance->php_version ?? $app->php_version,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return $this->unwrap($workspace->load(['app.instances', 'instance']), true);
    }

    private function callerNodeInstanceForPath(App $app, ?Node $callerNode, string $path): ?Instance
    {
        if (! $callerNode instanceof Node) {
            return null;
        }

        $app->loadMissing('instances');

        $matches = $app
            ->instances
            ->filter(function (Instance $instance) use ($callerNode): bool {
                $node = $this->placement->nodeForInstance($instance);

                return $node instanceof Node && $node->is($callerNode);
            })
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $instance = $matches->first();

        return $instance instanceof Instance ? $instance : null;
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveByName(string $name, ?string $appName): array
    {
        $query = Workspace::query()
            ->with(['app.instances', 'instance'])
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
     * @return array{type: 'workspace', workspace: Workspace}|array{type: 'app_root'|'inside_app', app: App, instance?: Instance}|array{type: 'unregistered'}
     */
    private function pathOwnership(string $cwd): array
    {
        /** @var list<Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->with(['app.instances', 'instance'])
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

            return ['type' => 'workspace', 'workspace' => $workspace];
        }

        $instanceMatch = $this->instanceForPath($cwd);

        if ($instanceMatch !== null) {
            ['app' => $app, 'instance' => $instance, 'path' => $path] = $instanceMatch;

            return (
                $path === $cwd
                    ? ['type' => 'app_root', 'app' => $app, 'instance' => $instance]
                    : ['type' => 'inside_app', 'app' => $app, 'instance' => $instance]
            );
        }

        // App owns no source path: cwd resolves through concrete instance
        // placement above; an otherwise-unmatched cwd is unregistered.
        return ['type' => 'unregistered'];
    }

    /**
     * @return list<App>
     */
    private function appsForCaller(?Node $callerNode): array
    {
        if ($callerNode instanceof Node && ! $this->roleGuard->nodeSupportsWorkspaces($callerNode)) {
            return [];
        }

        $query = App::query()->with(['instances']);

        if ($callerNode instanceof Node && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($callerNode)) {
            $query->where('node_id', $callerNode->id);
        }

        $apps = [];

        foreach ($query->get() as $app) {
            $supportsWorkspaces = $app->instances->contains(fn (Instance $instance): bool => $this->roleGuard->nodeSupportsWorkspaces(
                $this->placement->nodeForInstance($instance),
            ));

            if ($supportsWorkspaces) {
                $apps[] = $app;
            }
        }

        return $apps;
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
                'The --instance value does not match the workspace resolved from the current directory.',
                ['field' => 'instance'],
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

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function unwrap(Workspace $workspace, bool $isAdoption): array
    {
        $workspace->loadMissing(['app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "App not found for workspace '{$workspace->name}'.",
                ['field' => 'instance'],
            );
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "Node not found for workspace '{$workspace->name}'.",
                ['field' => 'instance'],
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

    private function concreteInstance(App $app, ?Instance $instance): Instance
    {
        if ($instance instanceof Instance) {
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

        if (! $resolved instanceof Instance) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "App '{$app->name}' has no concrete instance.",
                ['field' => 'instance', 'reason' => 'instance_required'],
            );
        }

        return $resolved;
    }

    private function ensureWorkspaceSupportsWorkspaces(Workspace $workspace): void
    {
        $workspace->loadMissing(['app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            return;
        }

        $this->ensureInstanceSupportsWorkspaces($app, $workspace->instance);
    }

    private function ensureInstanceSupportsWorkspaces(App $app, Instance $instance): void
    {
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                "Node not found for instance '{$this->selectionLabel($app, $instance)}'.",
                ['field' => 'instance'],
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
    }

    private function pathAllowedForWorkspace(string $path, Instance $instance): bool
    {
        $appPath = $this->instancePath($instance) ?? '';

        $appPath = rtrim($this->normalizePath($appPath), '/');

        return $this->normalizePath($path) !== $appPath;
    }

    private function firstWorkspaceMatch(App $app, string $workspaceName, Instance $instance): ?Workspace
    {
        $workspaces = Workspace::query()
            ->with(['app.instances', 'instance'])
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->get();

        return $workspaces->first(
            fn (Workspace $workspace): bool => $workspace->instance_id === $instance->id,
        );
    }

    /**
     * @return array{app: App, instance: Instance, path: string}|null
     */
    private function instanceForPath(string $cwd): ?array
    {
        /** @var list<array{app: App, instance: Instance, path: string}> $candidates */
        $candidates = [];

        /** @var list<App> $apps */
        $apps = App::query()
            ->with('instances')
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

            $this->ensureInstanceSupportsWorkspaces($candidate['app'], $candidate['instance']);

            return [
                'app' => $candidate['app'],
                'instance' => $candidate['instance'],
                'path' => $path,
            ];
        }

        return null;
    }

    private function instancePath(Instance $instance): ?string
    {
        $config = $instance->driver_config;

        if ($config instanceof OrbitInstanceDriverConfigData && is_string($config->path) && $config->path !== '') {
            return $config->path;
        }

        return null;
    }

    private function selectionLabel(App $app, ?Instance $instance): string
    {
        return $instance instanceof Instance ? "{$app->name}.{$instance->name}" : $app->name;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/') ?: '/';
    }

    private function pathMatches(string $candidate, string $cwd): bool
    {
        return $candidate === $cwd || str_starts_with($cwd, "{$candidate}/");
    }
}
