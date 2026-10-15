<?php

declare(strict_types=1);

/** @return list<string> */
function proxycli_namespaces(): array
{
    return ['App\Domain\ProxyCli', 'App\Infrastructure\ProxyCli', 'App\Actions\ProxyCli', 'App\Data\ProxyCli'];
}

// Core reaches proxycli only through plug-in points (docs/reference/extensions.md#plug-in-points). Each target has its
// own expectation: with a list of targets, Pest's not->toUse() reports no violation.
arch('the Caddy build never uses proxycli code')
    ->expect('App\Infrastructure\Caddy')
    ->not->toUse(proxycli_namespaces());

arch('the application provider never uses proxycli code')
    ->expect('App\Providers\ApplicationServiceProvider')
    ->not->toUse(proxycli_namespaces());
