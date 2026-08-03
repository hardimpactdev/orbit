<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppSelectorResolver
{
    public function __construct(
        private WorkspacePlacement $placement,
        private NodeAccessAuthorizer $authorizer,
    ) {}

    /**
     * @param  (callable(AppInstance): bool)|null  $instanceIsVisible
     */
    public function resolve(?string $selector, ?callable $instanceIsVisible = null): ?AppSelection
    {
        $value = is_string($selector) ? trim($selector) : '';

        if ($value === '') {
            return null;
        }

        $app = Project::query()
            ->with(['node', 'instances'])
            ->where('name', $value)
            ->first();

        if ($app instanceof Project) {
            return new AppSelection(app: $app, selector: $value);
        }

        $app = Project::query()
            ->with(['node', 'instances'])
            ->where('domain', $value)
            ->first();

        if ($app instanceof Project) {
            return new AppSelection(app: $app, selector: $value);
        }

        if (str_contains($value, '.')) {
            [$appName, $instanceSelector] = explode('.', $value, 2);
            $app = Project::query()
                ->with(['node', 'instances'])
                ->where('name', $appName)
                ->first();

            if ($app instanceof Project) {
                return new AppSelection(
                    app: $app,
                    instance: $this->resolveInstance(
                        $app,
                        $instanceSelector,
                        $value,
                        $instanceIsVisible,
                    ),
                    selector: $value,
                    instanceSelector: $instanceSelector,
                );
            }
        }

        $app = Project::query()
            ->with(['node', 'instances'])
            ->get()
            ->first(
                fn (Project $candidate): bool => (
                    $candidate->url() === "https://{$value}"
                    || $candidate->url() === $value
                ),
            );

        if ($app instanceof Project) {
            return new AppSelection(app: $app, selector: $value);
        }

        return null;
    }

    public function resolveRequired(?string $selector, string $field = 'instance'): AppSelection
    {
        $selection = $this->resolve($selector);

        if ($selection instanceof AppSelection) {
            return $selection;
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            'Project or instance not found. Pass --instance=<project.instance> explicitly.',
            ['field' => $field],
        );
    }

    /**
     * @param  (callable(AppInstance): bool)|null  $instanceIsVisible
     */
    public function requireInstance(
        AppSelection $selection,
        string $field = 'instance',
        ?callable $instanceIsVisible = null,
    ): AppSelection {
        if ($selection->instance instanceof AppInstance) {
            return $selection;
        }

        $selection->app->loadMissing('instances');
        $instances = $selection->app->instances->values();
        $instance = $instances->first();

        if ($instances->count() === 1 && $instance instanceof AppInstance) {
            return new AppSelection(
                app: $selection->app,
                instance: $instance,
                selector: $selection->selector,
                instanceSelector: $instance->name,
            );
        }

        $meta = [
            'field' => $field,
            'reason' => 'instance_required',
            'project' => $selection->app->name,
        ];
        $visibleInstances = $instanceIsVisible === null
            ? $instances
            : $instances->filter($instanceIsVisible)->values();

        if ($visibleInstances->count() === $instances->count()) {
            $meta['instances'] = $instances->pluck('name')->values()->all();
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "Project '{$selection->app->name}' requires a concrete instance selector.",
            $meta,
        );
    }

    public function resolveByPath(?string $path): ?AppSelection
    {
        $normalizedPath = is_string($path) ? trim($path) : '';

        if ($normalizedPath === '') {
            return null;
        }

        $normalizedPath = rtrim($normalizedPath, '/');

        $instanceSelection = $this->resolveInstanceByPath($normalizedPath);

        if ($instanceSelection instanceof AppSelection) {
            return $instanceSelection;
        }

        $app = Project::query()
            ->with(['node', 'instances'])
            ->get()
            ->first(function (Project $app) use ($normalizedPath): bool {
                $appPath = rtrim($app->path, '/');

                return (
                    $appPath !== ''
                    && ($normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/"))
                );
            });

        if ($app instanceof Project) {
            return new AppSelection(app: $app, selector: $normalizedPath);
        }

        $workspace = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return (
                    $workspacePath !== ''
                    && ($normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/"))
                );
            });

        if (! $workspace instanceof Workspace || ! $workspace->app instanceof Project) {
            return null;
        }

        return new AppSelection(
            app: $workspace->app,
            instance: $this->placement->instanceForWorkspace($workspace),
            selector: $normalizedPath,
        );
    }

    public function matchesWorkspace(Workspace $workspace, AppSelection $selection): bool
    {
        if ($workspace->app_id !== $selection->app->id) {
            return false;
        }

        if (! $selection->instance instanceof AppInstance) {
            return true;
        }

        $instance = $this->placement->instanceForWorkspace($workspace);

        return $instance instanceof AppInstance && $instance->id === $selection->instance->id;
    }

    public function applyAppConstraint(Builder $query, AppSelection $selection): Builder
    {
        return $query->where('app_id', $selection->app->id);
    }

    public function instanceIsVisibleTo(Node $caller, AppInstance $instance, string $permission): bool
    {
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            return false;
        }

        if ($this->authorizer->allows($caller, $node, $permission)) {
            return true;
        }

        return $permission !== 'instance:read' && $this->authorizer->allows($caller, $node, 'instance:read');
    }

    private function resolveInstanceByPath(string $path): ?AppSelection
    {
        $bestSelection = null;
        $bestLength = -1;

        Project::query()
            ->with(['node', 'instances'])
            ->get()
            ->each(function (Project $app) use ($path, &$bestSelection, &$bestLength): void {
                $instance = $this->placement->matchingOrbitInstanceForPath($app, $path);
                $config = $instance?->driver_config;

                if (! $instance instanceof AppInstance || ! $config instanceof OrbitAppInstanceDriverConfigData) {
                    return;
                }

                if (! is_string($config->path)) {
                    return;
                }

                $length = strlen(rtrim($config->path, '/'));

                if ($length <= $bestLength) {
                    return;
                }

                $bestSelection = new AppSelection(
                    app: $app,
                    instance: $instance,
                    selector: $path,
                    instanceSelector: $instance->name,
                );
                $bestLength = $length;
            });

        return $bestSelection;
    }

    /**
     * @param  (callable(AppInstance): bool)|null  $instanceIsVisible
     */
    private function resolveInstance(
        Project $app,
        string $instanceSelector,
        string $fullSelector,
        ?callable $instanceIsVisible,
    ): AppInstance {
        $visible = $app
            ->instances
            ->filter(function (mixed $instance) use ($instanceIsVisible): bool {
                return (
                    $instance instanceof AppInstance
                    && ($instanceIsVisible === null || $instanceIsVisible($instance))
                );
            })
            ->values();

        $needle = mb_strtolower(trim($instanceSelector));
        $exactNameMatches = $visible
            ->filter(function (mixed $instance) use ($needle): bool {
                return $instance instanceof AppInstance && mb_strtolower($instance->name) === $needle;
            })
            ->values();

        if ($exactNameMatches->count() === 1) {
            $exact = $exactNameMatches->first();

            if ($exact instanceof AppInstance) {
                return $exact;
            }
        }

        if ($exactNameMatches->count() > 1) {
            $this->throwAmbiguousInstance($app, $fullSelector, $exactNameMatches);
        }

        $matches = $visible
            ->filter(function (mixed $instance) use ($instanceSelector, $fullSelector, $app): bool {
                return $instance instanceof AppInstance
                && $this->placement->instanceMatchesSelector(
                    instance: $instance,
                    selector: $instanceSelector,
                    fullSelector: $fullSelector,
                    app: $app,
                );
            })
            ->values();

        $match = $matches->first();

        if ($matches->count() === 1 && $match instanceof AppInstance) {
            return $match;
        }

        if ($matches->count() > 1) {
            $this->throwAmbiguousInstance($app, $fullSelector, $matches);
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "Instance '{$fullSelector}' not found.",
            [
                'field' => 'instance',
                'project' => $app->name,
                'instance' => $instanceSelector,
            ],
        );
    }

    /**
     * @param  iterable<int, mixed>  $matches
     */
    private function throwAmbiguousInstance(Project $app, string $fullSelector, iterable $matches): never
    {
        $instanceNames = [];

        foreach ($matches as $instance) {
            if ($instance instanceof AppInstance) {
                $instanceNames[] = $instance->name;
            }
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "Instance selector '{$fullSelector}' is ambiguous.",
            [
                'field' => 'instance',
                'project' => $app->name,
                'selector' => $fullSelector,
                'instances' => $instanceNames,
            ],
        );
    }
}
