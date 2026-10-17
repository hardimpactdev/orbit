<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/** Staging survives failed finalization; ordinary deploy, rollback and resume cannot release it. */
final class GatewayReleaseHold
{
    public static function assertNone(): void
    {
        $path = rtrim(Config::string('orbit.home'), '/').'/gateway-release.paused';
        $marker = json_decode((string) @file_get_contents($path), true);
        if ((file_exists($path) && (! is_array($marker) || ($marker['staged_record'] ?? null) !== null)) || (Schema::hasTable('gateway_releases') && GatewayRelease::query()
            ->where('phases->hold->outcome', 'held')->where('outcome', '!=', 'verified')->exists())) {
            throw new GatewayReleaseException('hold', 'gateway.release_held', 'Finalize the exact staged record and SHA, or restore its snapshot and abandon it, before another release or automatic resume.');
        }
    }
}
