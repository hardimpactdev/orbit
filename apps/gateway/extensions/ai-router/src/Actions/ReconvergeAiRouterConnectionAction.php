<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ReconvergeAiRouterConnectionAction implements DatabaseConnectionUpdateHook
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterPlacement $placement,
        private AiRouterRuntimeLifecycle $runtime,
        private AiRouterCollectorEnvironment $environment,
    ) {}

    public function validate(DatabaseConnection $connection): void
    {
        if (! $this->state->enabled() || $this->state->cacheConnection() !== $connection->slug) {
            return;
        }
        $nodeId = $this->state->nodeId();
        if ($nodeId === null) {
            return;
        }
        $this->placement->node($nodeId);
        $this->placement->validateConnection($connection);
    }

    public function afterCommit(DatabaseConnection $connection): void
    {
        if (! $this->state->enabled() || $this->state->cacheConnection() !== $connection->slug) {
            return;
        }
        $nodeId = $this->state->nodeId();
        if ($nodeId === null) {
            return;
        }
        $node = $this->placement->node($nodeId);
        $connection = $this->placement->connection($connection->slug);
        $this->runtime->converge($node, $this->environment->build($connection));
    }
}
