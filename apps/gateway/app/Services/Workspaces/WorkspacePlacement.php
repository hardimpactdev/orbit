<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

final class WorkspacePlacement
{
    public function instanceForWorkspace(Workspace $workspace): ?Instance
    {
        $workspace->loadMissing('instance');

        return $workspace->instance instanceof Instance ? $workspace->instance : null;
    }

    public function nodeForWorkspace(Workspace $workspace): ?Node
    {
        $workspace->loadMissing(['app.node', 'instance']);

        $instance = $this->instanceForWorkspace($workspace);

        if ($instance instanceof Instance) {
            $node = $this->nodeForInstance($instance);

            if ($node instanceof Node) {
                return $node;
            }
        }

        return null;
    }

    public function nodeForInstance(Instance $instance): ?Node
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
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

        if ($config instanceof OrbitInstanceDriverConfigData && is_string($config->path) && $config->path !== '') {
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
            $config instanceof OrbitInstanceDriverConfigData
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

        if ($instance instanceof Instance) {
            $host = $this->instanceUrlHost($instance, $app);

            if ($host !== '') {
                return $host;
            }
        }

        return '';
    }

    public function instanceUrlHost(Instance $instance, App $app): string
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
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

    /**
     * Derive an instance's environment from its own placement rather than an
     * app-owned column. This mirrors AppRegistrar::registrationEnvironment: the
     * instance's driver-config domain plus its serving node role decide whether
     * it is a development or production runtime, so Instance stays the single
     * placement authority.
     */
    public function environmentForInstance(Instance $instance): string
    {
        $config = $instance->driver_config;
        $domain = $config instanceof OrbitInstanceDriverConfigData ? $config->domain : null;

        return $this->environmentFor(
            is_string($domain) && $domain !== '' ? $domain : null,
            $this->nodeForInstance($instance),
        );
    }

    public function environmentFor(?string $domain, ?Node $node): string
    {
        if ($domain === null || $domain === '') {
            return 'development';
        }

        if ($node instanceof Node && $this->isDevelopmentDomainForNode($domain, $node)) {
            return 'development';
        }

        return 'production';
    }

    private function isDevelopmentDomainForNode(string $domain, Node $node): bool
    {
        if (! $node->hasActiveRole('app-dev')) {
            return false;
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        return $tld !== '' && str_ends_with($domain, ".{$tld}");
    }

    public function matchingOrbitInstanceForPath(App $app, string $path): ?Instance
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

            if (! $config instanceof OrbitInstanceDriverConfigData || ! is_string($config->path)) {
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

    public function instanceContainsPath(Instance $instance, string $path): bool
    {
        if ($instance->driver !== InstanceDriver::Orbit) {
            return false;
        }

        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData || ! is_string($config->path)) {
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
        Instance $instance,
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

        if ($config instanceof OrbitInstanceDriverConfigData) {
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
