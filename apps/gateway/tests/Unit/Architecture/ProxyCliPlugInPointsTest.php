<?php

declare(strict_types=1);

/** @return list<string> */
function proxycli_namespaces(): array
{
    return ['App\Domain\ProxyCli', 'App\Infrastructure\ProxyCli', 'App\Actions\ProxyCli', 'App\Data\ProxyCli'];
}

// Core reaches proxycli only through plug-in points (docs/reference/extensions.md#plug-in-points). Each target has its
// own expectation: with a list of targets, Pest's not->toUse() fails only when every target uses the namespace.
arch('the Caddy build never uses proxycli code')
    ->expect('App\Infrastructure\Caddy')
    ->not->toUse(proxycli_namespaces());

arch('the application provider never uses proxycli code')
    ->expect('App\Providers\ApplicationServiceProvider')
    ->not->toUse(proxycli_namespaces());

arch('the Node footprint never uses proxycli code')
    ->expect('App\Domain\Fleet')
    ->not->toUse(proxycli_namespaces());

arch('the core footprint artifacts never use proxycli code')
    ->expect('App\Infrastructure\Fleet')
    ->not->toUse(proxycli_namespaces());

arch('the fleet provider never uses proxycli code')
    ->expect('App\Providers\FleetServiceProvider')
    ->not->toUse(proxycli_namespaces());

arch('private DNS never uses proxycli code')
    ->expect('App\Infrastructure\AppDev')
    ->not->toUse(proxycli_namespaces());

arch('reserved hostnames never use proxycli code')
    ->expect('App\Domain\Routes')
    ->not->toUse(proxycli_namespaces());
