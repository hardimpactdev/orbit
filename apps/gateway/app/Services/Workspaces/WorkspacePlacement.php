<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;

final class WorkspacePlacement
{
    public function instanceForWorkspace(Workspace $workspace): ?AppInstance
    {
        $workspace->loadMissing('appInstance');

        return $workspace->appInstance instanceof AppInstance ? $workspace->appInstance : null;
    }

    public function nodeForWorkspace(Workspace $workspace): ?Node
    {
        $workspace->loadMissing(['app.node', 'appInstance']);

        $instance = $this->instanceForWorkspace($workspace);

        if ($instance instanceof AppInstance) {
            $node = $this->nodeForInstance($instance);

            if ($node instanceof Node) {
                return $node;
            }
        }

        return null;
    }

    public function nodeForInstance(AppInstance $instance): ?Node
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
            return null;
        }

        if ($config->node_id !== null) {
            $node = Node::query()->find($config->node_id);

            if ($node instanceof Node) {
                return $node;
            }
        }

        if (is_string($config->node) && $config->node !== '') {
            return Node::query()
                ->where('name', $config->node)
                ->first();
        }

        return null;
    }

    public function appPathForWorkspace(Workspace $workspace): ?string
    {
        $workspace->loadMissing('app');
        $instance = $this->instanceForWorkspace($workspace);
        $config = $instance?->driver_config;

        if ($config instanceof OrbitAppInstanceDriverConfigData && is_string($config->path) && $config->path !== '') {
            return $config->path;
        }

        return $workspace->app?->path;
    }

    public function documentRootForWorkspace(Workspace $workspace): string
    {
        $workspace->loadMissing('app');
        $instance = $this->instanceForWorkspace($workspace);
        $config = $instance?->driver_config;

        if (
            $config instanceof OrbitAppInstanceDriverConfigData
            && is_string($config->document_root)
            && $config->document_root !== ''
        ) {
            return $config->document_root;
        }

        return (string) $workspace->app?->document_root;
    }

    public function workspaceDomain(Workspace $workspace): string
    {
        $workspace->loadMissing('app.node');
        $app = $workspace->app;

        if (! $app instanceof App) {
            return $workspace->name;
        }

        $host = $this->baseUrlHost($workspace, $app);

        if ($host === '') {
            return $workspace->name;
        }

        return "{$workspace->name}.{$host}";
    }

    public function baseUrlHost(Workspace $workspace, App $app): string
    {
        $instance = $this->instanceForWorkspace($workspace);

        if ($instance instanceof AppInstance) {
            $host = $this->instanceUrlHost($instance, $app);

            if ($host !== '') {
                return $host;
            }
        }

        return '';
    }

    public function instanceUrlHost(AppInstance $instance, App $app): string
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
            return '';
        }

        if (is_string($config->domain) && $config->domain !== '') {
            return $config->domain;
        }

        $node = $this->nodeForInstance($instance);
        $tld = $node instanceof Node && is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    public function matchingOrbitInstanceForPath(App $app, string $path): ?AppInstance
    {
        $path = rtrim($path, '/');

        if ($path === '') {
            return null;
        }

        $bestMatch = null;
        $bestLength = -1;

        $app->loadMissing('instances');

        foreach ($app->instances as $instance) {
            if (! $this->instanceContainsPath($instance, $path)) {
                continue;
            }

            $config = $instance->driver_config;

            if (! $config instanceof OrbitAppInstanceDriverConfigData || ! is_string($config->path)) {
                continue;
            }

            $length = strlen(rtrim($config->path, '/'));

            if ($length > $bestLength) {
                $bestMatch = $instance;
                $bestLength = $length;
            }
        }

        return $bestMatch;
    }

    public function instanceContainsPath(AppInstance $instance, string $path): bool
    {
        if ($instance->driver !== AppInstanceDriver::Orbit) {
            return false;
        }

        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData || ! is_string($config->path)) {
            return false;
        }

        $instancePath = rtrim($config->path, '/');

        if ($instancePath === '') {
            return false;
        }

        $path = rtrim($path, '/');

        return $path === $instancePath || str_starts_with($path, "{$instancePath}/");
    }

    public function instanceMatchesSelector(
        AppInstance $instance,
        string $selector,
        ?string $fullSelector = null,
        ?App $app = null,
    ): bool {
        $needle = mb_strtolower(trim($selector));
        $fullNeedle = $fullSelector !== null ? mb_strtolower(trim($fullSelector)) : null;

        if ($needle === '') {
            return false;
        }

        if (mb_strtolower($instance->name) === $needle) {
            return true;
        }

        $config = $instance->driver_config;

        if ($config instanceof OrbitAppInstanceDriverConfigData) {
            if (is_string($config->domain)) {
                $domain = mb_strtolower(trim($config->domain));

                if ($domain !== '' && ($domain === $needle || $domain === $fullNeedle)) {
                    return true;
                }
            }

            if (is_string($config->node) && mb_strtolower($config->node) === $needle) {
                return true;
            }
        }

        $node = $this->nodeForInstance($instance);

        if ($node instanceof Node) {
            if (mb_strtolower($node->name) === $needle) {
                return true;
            }

            $tld = is_string($node->tld) ? mb_strtolower(trim($node->tld, '.')) : '';

            if ($tld !== '' && $tld === $needle) {
                return true;
            }
        }

        if ($app instanceof App && $fullNeedle !== null) {
            return mb_strtolower("{$app->name}.{$instance->name}") === $fullNeedle;
        }

        return false;
    }
}
