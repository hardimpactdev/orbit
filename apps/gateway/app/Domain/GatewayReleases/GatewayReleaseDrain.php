<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** A live, maintained barrier; a prior successful observation is not a lease. */
interface GatewayReleaseDrain
{
    public function assertHeld(string $targetSha): void;
}
