<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppProxyRouteDomainInstanceResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function resolve(App $app, string $domain): ?Instance
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

    private function instanceDomainMatchesRoute(App $app, Instance $instance, string $domain): bool
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

    private function isBareAppRouteDomain(App $app, string $domain): bool
    {
        $primaryDomain = $this->placement->runtimeDomain($app, null);

        if (is_string($primaryDomain) && mb_strtolower(trim($primaryDomain)) === $domain) {
            return true;
        }

        $nodeTld = $this->placement->runtimeNode($app, null)?->tld;
        $tld = is_string($nodeTld) ? trim($nodeTld, characters: '.') : '';

        if ($tld === '') {
            return mb_strtolower($app->name) === $domain;
        }

        return mb_strtolower("{$app->name}.{$tld}") === $domain;
    }
}
