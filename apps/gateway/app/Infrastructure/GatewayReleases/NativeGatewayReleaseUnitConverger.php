<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use Illuminate\Support\Facades\Config;

/** Sends release-unit identity and retention policy to the Gateway Node. */
final readonly class NativeGatewayReleaseUnitConverger implements GatewayReleaseUnitConverger
{
    public function __construct(private GatewayReleaseCommands $commands) {}

    public function converge(): void
    {
        $this->commands->convergeUnits(
            GatewayApplicationPath::resolve(),
            Config::string('orbit.home'),
            'orbit',
            [
                'journal_max_mb' => Config::integer('retention.journal_max_mb'),
                'journal_keep_free_mb' => Config::integer('retention.journal_keep_free_mb'),
                'journal_days' => Config::integer('retention.journal_days'),
                'journal_runtime_max_mb' => Config::integer('retention.journal_runtime_max_mb'),
                'log_rotate_mb' => Config::integer('retention.log_rotate_mb'),
                'log_rotate_keep' => Config::integer('retention.log_rotate_keep'),
            ],
        );
    }
}
