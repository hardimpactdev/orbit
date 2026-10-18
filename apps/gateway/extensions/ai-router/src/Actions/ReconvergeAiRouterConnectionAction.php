<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class ReconvergeAiRouterConnectionAction implements DatabaseConnectionUpdateHook
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterPlacement $placement,
        private ConvergeAiRouterAction $converge,
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
        $this->converge->execute(force: true);
    }
}
