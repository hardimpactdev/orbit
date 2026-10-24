<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

interface GatewayReleaseObserver
{
    public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory;
}
