<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Shared\ResourceOperationException;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ShowAiRouterProviderAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSnapshotStore $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $provider): array
    {
        $this->state->assertEnabled();
        $pool = $this->snapshots->snapshot()?->provider($provider);

        if ($pool === null) {
            throw new ResourceOperationException(
                'resource.not_found',
                "Provider [{$provider}] was not found.",
                404,
            );
        }

        return $pool->toArray();
    }
}
