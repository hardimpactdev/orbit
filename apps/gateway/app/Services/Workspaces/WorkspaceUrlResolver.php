<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class WorkspaceUrlResolver
{
    public function url(Workspace $workspace): string
    {
        $workspace->loadMissing(['app.node', 'app.instances']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return "https://{$workspace->name}";
        }

        return "https://{$workspace->name}.{$this->placementUrlHost($workspace, $app)}";
    }

    private function placementUrlHost(Workspace $workspace, App $app): string
    {
        $instance = $this->matchingOrbitInstance($workspace, $app);

        if ($instance instanceof AppInstance) {
            $host = $this->orbitInstanceUrlHost($instance, $app);

            if ($host !== '') {
                return $host;
            }
        }

        $host = parse_url($app->url(), PHP_URL_HOST);

        if (! is_string($host)) {
            return $app->name;
        }

        return $host;
    }

    private function matchingOrbitInstance(Workspace $workspace, App $app): ?AppInstance
    {
        $workspacePath = rtrim(string: $workspace->path, characters: '/');

        if ($workspacePath === '') {
            return null;
        }

        $bestMatch = null;
        $bestPathLength = -1;

        foreach ($app->instances as $instance) {
            if (! $this->instanceContainsWorkspace($instance, $workspacePath)) {
                continue;
            }

            $config = $instance->driver_config;

            if (! $config instanceof OrbitAppInstanceDriverConfigData || ! is_string($config->path)) {
                continue;
            }

            $pathLength = strlen(rtrim(string: $config->path, characters: '/'));

            if ($pathLength > $bestPathLength) {
                $bestMatch = $instance;
                $bestPathLength = $pathLength;
            }
        }

        return $bestMatch;
    }

    private function instanceContainsWorkspace(AppInstance $instance, string $workspacePath): bool
    {
        if ($instance->driver !== AppInstanceDriver::Orbit) {
            return false;
        }

        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData || ! is_string($config->path)) {
            return false;
        }

        $instancePath = rtrim(string: $config->path, characters: '/');

        if ($instancePath === '') {
            return false;
        }

        return $workspacePath === $instancePath || str_starts_with($workspacePath, $instancePath.'/');
    }

    private function orbitInstanceUrlHost(AppInstance $instance, App $app): string
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
            return '';
        }

        if (is_string($config->domain) && $config->domain !== '') {
            return $config->domain;
        }

        $node = $this->nodeForConfig($config);
        $tld = '';

        if ($node instanceof Node && is_string($node->tld)) {
            $tld = trim(string: $node->tld, characters: '.');
        }

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    private function nodeForConfig(OrbitAppInstanceDriverConfigData $config): ?Node
    {
        if ($config->node_id !== null) {
            return Node::query()->find($config->node_id);
        }

        if (! is_string($config->node) || $config->node === '') {
            return null;
        }

        return Node::query()->where('name', $config->node)->first();
    }
}
