<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Infrastructure\GatewayReleases\NativeGatewayReleaseExecutionOwner;
use Illuminate\Container\Attributes\Bind;

/** Read-only admission before release locks or shared writes. */
#[Bind(NativeGatewayReleaseExecutionOwner::class)]
interface GatewayReleaseExecutionOwner
{
    public function assertAllowed(?string $sha = null): void;
}
