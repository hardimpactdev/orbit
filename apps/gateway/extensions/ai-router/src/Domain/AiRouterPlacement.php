<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Node;

final readonly class AiRouterPlacement
{
    public function connection(string $slug): DatabaseConnection
    {
        $connection = DatabaseConnection::query()->where('slug', $slug)->first();

        if (! $connection instanceof DatabaseConnection) {
            throw new ResourceOperationException(
                'ai_router.cache_missing',
                'Enable requires a registered Redis Database connection for shared Valkey.',
                422,
            );
        }

        return $this->validateConnection($connection);
    }

    public function validateConnection(DatabaseConnection $connection): DatabaseConnection
    {
        if ($connection->driver !== DatabaseDriver::Redis) {
            throw new ResourceOperationException(
                'ai_router.cache_invalid',
                'The ai-router cache connection must use the redis driver.',
                422,
            );
        }

        if ($connection->node_id === null) {
            return $connection;
        }

        $node = Node::query()->find($connection->node_id);

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                'ai_router.cache_unplaced',
                'The ai-router cache connection names a Node that is not in the fleet.',
                422,
            );
        }

        $hasDatabase = $node->roles()
            ->where('role', RoleName::Database->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();

        if (! $hasDatabase) {
            throw new ResourceOperationException(
                'ai_router.cache_unplaced',
                'Shared Valkey must run on a Node with the active database role.',
                422,
            );
        }

        return $connection;
    }

    public function node(int $nodeId): Node
    {
        $node = Node::query()->find($nodeId);

        if (
            ! $node instanceof Node
            || $node->status !== LifecycleStatus::Active
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
            || $node->platform !== 'linux'
        ) {
            throw new ResourceOperationException(
                'ai_router.node_invalid',
                'ai-router enable requires an active Linux Node with a WireGuard address.',
                422,
            );
        }

        return $node;
    }
}
