<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ShowAiRouterStatusAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSnapshotStore $snapshots,
    ) {}

    public function execute(): AiRouterStatusData
    {
        return new AiRouterStatusData(
            $this->state->enabled(),
            $this->state->nodeId(),
            $this->state->cacheConnection(),
            $this->snapshots->snapshot()?->collectedAt,
            AiRouterHostname::Value,
        );
    }
}
