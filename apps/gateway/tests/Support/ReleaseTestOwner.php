<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;

/** Pipeline fixtures have no service account or production configuration. Native admission has separate tests. */
final readonly class ReleaseTestOwner implements GatewayReleaseExecutionOwner
{
    public function assertAllowed(?string $sha = null): void {}
}
