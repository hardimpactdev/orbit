<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Orbit\Extensions\AiRouter\Domain\AiRouterModel;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ListAiRouterModelsAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSnapshotStore $snapshots,
    ) {}

    /**
     * @return list<AiRouterModel>
     */
    public function execute(): array
    {
        $this->state->assertEnabled();
        $snapshot = $this->snapshots->snapshot();

        return $snapshot->models ?? [];
    }
}
