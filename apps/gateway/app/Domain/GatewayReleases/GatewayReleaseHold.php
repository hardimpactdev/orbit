<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Schema;

/** Staging survives failed finalization; ordinary deploy, rollback and resume cannot release it. */
final class GatewayReleaseHold
{
    public static function assertNone(): void
    {
        if (Schema::hasTable('gateway_releases') && GatewayRelease::query()
            ->where('phases->hold->outcome', 'held')->where('outcome', '!=', 'verified')->exists()) {
            throw new GatewayReleaseException('hold', 'gateway.release_held', 'Finalize the exact staged record and SHA before another release or automatic resume.');
        }
    }
}
