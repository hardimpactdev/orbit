<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
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
     * @param  (callable(Instance): bool)|null  $instanceIsVisible
     */
    public function resolve(?string $selector, ?callable $instanceIsVisible = null): ?AppSelection
    {
        $value = is_string($selector) ? trim($selector) : '';

        if ($value === '') {
            return null;
        }

        $app = App::query()
            ->with('instances')
            ->where('name', $value)
            ->first();

        if ($app instanceof App) {
            return new AppSelection(app: $app, selector: $value);
        }

        // An exact "app.instance-name" selector takes precedence over the
        // instance-authoritative domain/alias resolution below: it names a
        // concrete instance explicitly and must not be shadowed by an instance
        // whose serving host happens to equal the selector string.
        if (str_contains($value, '.')) {
            [$appName, $instanceSelector] = explode('.', $value, 2);
            $exactApp = App::query()
                ->with('instances')
                ->where('name', $appName)
                ->first();

            if ($exactApp instanceof App) {
                $needle = mb_strtolower(trim($instanceSelector));
                $exactInstance = $exactApp
                    ->instances
                    ->first(
                        static fn (Instance $instance): bool => (
                            mb_strtolower($instance->name) === $needle
                            && ($instanceIsVisible === null || $instanceIsVisible($instance))
                        ),
                    );

                if ($exactInstance instanceof Instance) {
                    return new AppSelection(
                        app: $exactApp,
                        instance: $exactInstance,
                        selector: $value,
                        instanceSelector: $instanceSelector,
                    );
                }
            }
        }

        // Domain resolution is instance-authoritative: match an app whose
        // concrete instance serves this host, returning that instance so the
        // caller does not re-resolve an ambiguous placement.
        $app = App::query()
            ->with('instances')
            ->get()
            ->first(fn (App $candidate): bool => $this->placement->appHasUrl($candidate, $value));

        if ($app instanceof App) {
            return new AppSelection(
                app: $app,
                instance: $this->placement->instanceForUrl($app, $value),
                selector: $value,
            );
        }

        if (str_contains($value, '.')) {
            [$appName, $instanceSelector] = explode('.', $value, 2);
            $app = App::query()
                ->with('instances')
                ->where('name', $appName)
                ->first();

            if ($app instanceof App) {
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
            'App or instance not found. Pass --instance=<app.instance> explicitly.',
            ['field' => $field],
        );
    }

    /**
     * @param  (callable(Instance): bool)|null  $instanceIsVisible
     */
    public function requireInstance(
        AppSelection $selection,
        string $field = 'instance',
        ?callable $instanceIsVisible = null,
    ): AppSelection {
        if ($selection->instance instanceof Instance) {
            return $selection;
        }

        $selection->app->loadMissing('instances');
        $instances = $selection->app->instances->values();
        $instance = $instances->first();

        if ($instances->count() === 1 && $instance instanceof Instance) {
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
            'app' => $selection->app->name,
        ];
        $visibleInstances = $instanceIsVisible === null
            ? $instances
            : $instances->filter($instanceIsVisible)->values();

        if ($visibleInstances->count() === $instances->count()) {
            $meta['instances'] = $instances->pluck('name')->values()->all();
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "App '{$selection->app->name}' requires a concrete instance selector.",
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

        // App owns no source path: cwd/path selection resolves through concrete
        // instance placement (above) and workspace paths (below) only.
        $workspace = Workspace::query()
            ->with(['app.instances', 'instance'])
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return (
                    $workspacePath !== ''
                    && ($normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/"))
                );
            });

        if (! $workspace instanceof Workspace || ! $workspace->app instanceof App) {
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

        if (! $selection->instance instanceof Instance) {
            return true;
        }

        $instance = $this->placement->instanceForWorkspace($workspace);

        return $instance instanceof Instance && $instance->id === $selection->instance->id;
    }

    public function applyAppConstraint(Builder $query, AppSelection $selection): Builder
    {
        return $query->where('app_id', $selection->app->id);
    }

    public function instanceIsVisibleTo(Node $caller, Instance $instance, string $permission): bool
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

        App::query()
            ->with('instances')
            ->get()
            ->each(function (App $app) use ($path, &$bestSelection, &$bestLength): void {
                $instance = $this->placement->matchingOrbitInstanceForPath($app, $path);
                $config = $instance?->driver_config;

                if (! $instance instanceof Instance || ! $config instanceof OrbitInstanceDriverConfigData) {
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
     * @param  (callable(Instance): bool)|null  $instanceIsVisible
     */
    private function resolveInstance(
        App $app,
        string $instanceSelector,
        string $fullSelector,
        ?callable $instanceIsVisible,
    ): Instance {
        $visible = $app
            ->instances
            ->filter(
                static fn (mixed $instance): bool => (
                    $instance instanceof Instance
                    && ($instanceIsVisible === null || $instanceIsVisible($instance))
                ),
            )
            ->values();

        $needle = mb_strtolower(trim($instanceSelector));
        $exactNameMatches = $visible
            ->filter(
                static fn (mixed $instance): bool => (
                    $instance instanceof Instance
                    && mb_strtolower($instance->name) === $needle
                ),
            )
            ->values();

        if ($exactNameMatches->count() === 1) {
            $exact = $exactNameMatches->first();

            if ($exact instanceof Instance) {
                return $exact;
            }
        }

        $matches = $exactNameMatches->count() > 1
            ? $exactNameMatches
            : $visible
                ->filter(
                    fn (mixed $instance): bool => $instance instanceof Instance
                    && $this->placement->instanceMatchesSelector(
                        instance: $instance,
                        selector: $instanceSelector,
                        fullSelector: $fullSelector,
                        app: $app,
                    ),
                )
                ->values();

        $match = $matches->first();

        if ($matches->count() === 1 && $match instanceof Instance) {
            return $match;
        }

        if ($matches->count() > 1) {
            $instanceNames = [];

            foreach ($matches as $instance) {
                if ($instance instanceof Instance) {
                    $instanceNames[] = $instance->name;
                }
            }

            throw new AppSelectionResolutionFailed(
                'validation_failed',
                "Instance selector '{$fullSelector}' is ambiguous.",
                [
                    'field' => 'instance',
                    'app' => $app->name,
                    'selector' => $fullSelector,
                    'instances' => $instanceNames,
                ],
            );
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "Instance '{$fullSelector}' not found.",
            [
                'field' => 'instance',
                'app' => $app->name,
                'instance' => $instanceSelector,
            ],
        );
    }
}
