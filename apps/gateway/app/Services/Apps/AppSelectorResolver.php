<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppSelectorResolver
{
    public function __construct(
        private WorkspacePlacement $placement,
    ) {}

    public function resolve(?string $selector): ?AppSelection
    {
        $value = $this->normalizeSelector($selector);

        if ($value === null) {
            return null;
        }

        $app = App::query()
            ->with(['node', 'instances'])
            ->where('name', $value)
            ->first();

        if ($app instanceof App) {
            return new AppSelection(app: $app, selector: $value);
        }

        $app = App::query()
            ->with(['node', 'instances'])
            ->where('domain', $value)
            ->first();

        if ($app instanceof App) {
            return new AppSelection(app: $app, selector: $value);
        }

        if (str_contains($value, '.')) {
            [$appName, $instanceSelector] = explode('.', $value, 2);
            $app = App::query()
                ->with(['node', 'instances'])
                ->where('name', $appName)
                ->first();

            if ($app instanceof App) {
                return new AppSelection(
                    app: $app,
                    instance: $this->resolveInstance($app, $instanceSelector, $value),
                    selector: $value,
                    instanceSelector: $instanceSelector,
                );
            }
        }

        $app = App::query()
            ->with(['node', 'instances'])
            ->get()
            ->first(
                fn (App $candidate): bool => $candidate->url() === "https://{$value}" || $candidate->url() === $value,
            );

        if ($app instanceof App) {
            return new AppSelection(app: $app, selector: $value);
        }

        return null;
    }

    public function resolveRequired(?string $selector, string $field = 'app'): AppSelection
    {
        $selection = $this->resolve($selector);

        if ($selection instanceof AppSelection) {
            return $selection;
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            'App not found. Pass --app=<name> explicitly.',
            ['field' => $field],
        );
    }

    public function resolveByPath(?string $path): ?AppSelection
    {
        $normalizedPath = $this->normalizeSelector($path);

        if ($normalizedPath === null) {
            return null;
        }

        $normalizedPath = rtrim($normalizedPath, '/');

        $instanceSelection = $this->resolveInstanceByPath($normalizedPath);

        if ($instanceSelection instanceof AppSelection) {
            return $instanceSelection;
        }

        $app = App::query()
            ->with(['node', 'instances'])
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim($app->path, '/');

                return (
                    $appPath !== ''
                    && ($normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/"))
                );
            });

        if ($app instanceof App) {
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

    private function normalizeSelector(?string $selector): ?string
    {
        if (! is_string($selector)) {
            return null;
        }

        $value = trim($selector);

        return $value !== '' ? $value : null;
    }

    private function resolveInstanceByPath(string $path): ?AppSelection
    {
        $bestSelection = null;
        $bestLength = -1;

        App::query()
            ->with(['node', 'instances'])
            ->get()
            ->each(function (App $app) use ($path, &$bestSelection, &$bestLength): void {
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

    private function resolveInstance(App $app, string $instanceSelector, string $fullSelector): AppInstance
    {
        $matches = $app
            ->instances
            ->filter(fn (AppInstance $instance): bool => $this->placement->instanceMatchesSelector(
                instance: $instance,
                selector: $instanceSelector,
                fullSelector: $fullSelector,
                app: $app,
            ))
            ->values();

        $match = $matches->first();

        if ($matches->count() === 1 && $match instanceof AppInstance) {
            return $match;
        }

        if ($matches->count() > 1) {
            $instanceNames = [];

            foreach ($matches as $instance) {
                if ($instance instanceof AppInstance) {
                    $instanceNames[] = $instance->name;
                }
            }

            throw new AppSelectionResolutionFailed(
                'validation_failed',
                "App instance selector '{$fullSelector}' is ambiguous.",
                [
                    'field' => 'app',
                    'app' => $app->name,
                    'selector' => $fullSelector,
                    'instances' => $instanceNames,
                ],
            );
        }

        throw new AppSelectionResolutionFailed(
            'validation_failed',
            "App instance '{$fullSelector}' not found.",
            [
                'field' => 'app',
                'app' => $app->name,
                'instance' => $instanceSelector,
            ],
        );
    }
}
