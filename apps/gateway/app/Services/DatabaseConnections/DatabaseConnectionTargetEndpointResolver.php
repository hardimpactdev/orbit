<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardServiceAddress;

final readonly class DatabaseConnectionTargetEndpointResolver
{
    public function __construct(
        private NodeWireGuardServiceAddress $serviceAddress,
        private ManagedDockerMysqlEndpointResolver $managedDockerMysqlEndpointResolver,
    ) {}

    /**
     * @return array{host: ?string, port: ?int}
     */
    public function forTarget(DatabaseConnectionTarget $target): array
    {
        $connection = $target->connection()->first();

        if (! $connection instanceof DatabaseConnection) {
            throw new \RuntimeException('Database connection target has no connection.');
        }

        return $this->forConnectionOnNode($connection, $this->targetNode($target));
    }

    /**
     * @return array{host: ?string, port: ?int}
     */
    public function forConnectionOnNode(DatabaseConnection $connection, Node $targetNode): array
    {
        if ($connection->driver === 'sqlite') {
            return [
                'host' => $connection->host,
                'port' => $connection->port,
            ];
        }

        $managedDockerMysql = $this->managedDockerMysqlEndpointResolver->resolve($connection, $targetNode);

        if ($managedDockerMysql !== null) {
            return $managedDockerMysql;
        }

        $host = $connection->host;

        if ($connection->node instanceof Node) {
            $host = $this->serviceAddress->forServiceOn($connection->node, $targetNode, $connection->driver);
        }

        return [
            'host' => $host,
            'port' => $connection->port,
        ];
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->app instanceof App && $target->app->node instanceof Node) {
            return $target->app->node;
        }

        if (
            $target->workspace instanceof Workspace
            && $target->workspace->app instanceof App
            && $target->workspace->app->node instanceof Node
        ) {
            return $target->workspace->app->node;
        }

        throw new \RuntimeException('Database connection target has no owning node.');
    }
}
