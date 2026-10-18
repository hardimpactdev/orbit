<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;

final readonly class ShowAiRouterStatusAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterRenameCutover $cutover,
        private AiRouterSnapshotStore $snapshots,
    ) {}

    public function execute(): AiRouterStatusData
    {
        $pending = $this->cutover->pending();

        return new AiRouterStatusData(
            $this->state->enabled(),
            $this->state->nodeId(),
            $this->state->cacheConnection(),
            $pending ? null : $this->snapshots->snapshot()?->collectedAt,
            AiRouterHostname::Value,
            $pending,
        );
    }
}
