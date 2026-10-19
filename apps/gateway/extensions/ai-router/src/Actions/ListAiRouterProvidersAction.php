<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ListAiRouterProvidersAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSnapshotStore $snapshots,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $this->state->assertEnabled();

        return $this->snapshots->snapshot()?->toArray()['providers'] ?? [];
    }
}
