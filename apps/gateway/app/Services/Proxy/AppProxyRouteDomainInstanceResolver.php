<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\AppInstance;
use App\Models\Project;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppProxyRouteDomainInstanceResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function resolve(Project $app, string $domain): ?AppInstance
    {
        $domain = mb_strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $app->loadMissing('instances');

        foreach ($app->instances as $instance) {
            if ($this->instanceDomainMatchesRoute($app, $instance, $domain)) {
                return $instance;
            }
        }

        return null;
    }

    private function instanceDomainMatchesRoute(Project $app, AppInstance $instance, string $domain): bool
    {
        $instanceDomain = mb_strtolower($this->placement->instanceUrlHost($instance, $app));

        if ($instanceDomain === '' || $instanceDomain !== $domain) {
            return false;
        }

        return (
            mb_strtolower("{$app->name}.{$instance->name}") === $domain
            || ! $this->isBareAppRouteDomain($app, $domain)
        );
    }

    private function isBareAppRouteDomain(Project $app, string $domain): bool
    {
        if (is_string($app->domain) && mb_strtolower(trim($app->domain)) === $domain) {
            return true;
        }

        $app->loadMissing('node');
        $tld = is_string($app->node?->tld) ? trim($app->node?->tld, characters: '.') : '';

        if ($tld === '') {
            return mb_strtolower($app->name) === $domain;
        }

        return mb_strtolower("{$app->name}.{$tld}") === $domain;
    }
}
