<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Infrastructure\GatewayReleases\GatewayReleaseRequests;
use App\Models\GatewayRelease;

/**
 * Queues a rollback to a retained release and starts its release unit. The target is checked before anything is queued, and again under the release lock.
 */
final readonly class StartGatewayReleaseRollbackAction
{
    public function __construct(
        private RollbackGatewayReleaseAction $rollback,
        private GatewayReleaseRequests $requests,
    ) {}

    public function execute(string $release): GatewayRelease
    {
        $sha = $this->rollback->check($release);

        return $this->requests->start('rollback', $release, $sha);
    }
}
