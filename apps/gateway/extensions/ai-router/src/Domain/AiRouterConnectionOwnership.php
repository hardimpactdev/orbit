<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

final readonly class AiRouterConnectionOwnership implements DatabaseConnectionRemovalGuard
{
    public function __construct(private AiRouterState $state) {}

    public function assertRemovable(DatabaseConnection $connection): void
    {
        if ($this->state->enabled() && $this->state->cacheConnection() === $connection->slug) {
            throw new ResourceOperationException(
                'database_connection.required_by_ai_router',
                "Database connection [{$connection->slug}] is used by the AI router collector. Tear down ai-router before removing it.",
                409,
            );
        }
    }
}
