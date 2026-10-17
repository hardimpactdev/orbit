<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** Read-only admission before release locks or shared writes. */
interface GatewayReleaseExecutionOwner
{
    public function assertAllowed(?string $sha = null): void;
}
