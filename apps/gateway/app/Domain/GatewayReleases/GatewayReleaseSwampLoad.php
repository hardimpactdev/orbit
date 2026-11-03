<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** Loads a release commit into Swamp serve before the switch ([Load Swamp](/reference/gateway-recovery#load-swamp)). */
interface GatewayReleaseSwampLoad
{
    /**
     * @return array{outcome:'loaded',operation_id:int}
     *
     * @throws GatewayReleaseException when Swamp does not serve the commit
     */
    public function load(string $sha): array;
}
