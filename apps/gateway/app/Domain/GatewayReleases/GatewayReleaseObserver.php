<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** Reads the release layout's live state. `$runningApplication` decides whether the serving release supersedes it. */
interface GatewayReleaseObserver
{
    public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory;
}
