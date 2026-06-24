<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardServiceAddress;

final readonly class DatabaseConnectionTargetEndpointResolver
{
    public function __construct(
        private NodeWireGuardServiceAddress $serviceAddress,
    ) {}

    /**
     * @return array{host: ?string, port: ?int}
     */
    public function forTarget(DatabaseConnectionTarget $target): array
    {
        return $this->forConnectionOnNode($target->connection, $this->targetNode($target));
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

        $managedDockerMysql = $this->managedDockerMysqlEndpoint($connection, $targetNode);

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

    /**
     * @return array{host: string, port: int}|null
     */
    private function managedDockerMysqlEndpoint(DatabaseConnection $connection, Node $targetNode): ?array
    {
        if ($connection->driver !== 'mysql' || ! $connection->node instanceof Node) {
            return null;
        }

        if (! $connection->node->is($targetNode)) {
            return null;
        }

        $process = $this->matchingManagedDockerMysqlProcess($connection);

        if (! $process instanceof Process) {
            return null;
        }

        $targetPort = $this->managedMysqlTargetPort($process);

        return [
            'host' => $process->name,
            'port' => $targetPort,
        ];
    }

    private function matchingManagedDockerMysqlProcess(DatabaseConnection $connection): ?Process
    {
        if ($connection->host === null || $connection->port === null || $connection->node_id === null) {
            return null;
        }

        $processes = Process::query()
            ->where('node_id', $connection->node_id)
            ->where('runtime', ProcessRuntime::Docker)
            ->withRuntimeService('mysql')
            ->get();

        foreach ($processes as $process) {
            $endpoint = is_array($process->runtime_config['endpoint'] ?? null)
                ? $process->runtime_config['endpoint']
                : [];

            $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';
            $port = $endpoint['port'] ?? null;

            if (
                $host === trim((string) $connection->host)
                && is_numeric($port)
                && (int) $port === (int) $connection->port
            ) {
                return $process;
            }
        }

        return null;
    }

    private function managedMysqlTargetPort(Process $process): int
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $ports = is_array($config['ports'] ?? null) ? $config['ports'] : [];

        foreach ($ports as $port) {
            if (! is_array($port)) {
                continue;
            }

            $target = $port['target'] ?? null;

            if (is_int($target) && $target > 0) {
                return $target;
            }

            if (is_numeric($target) && (int) $target > 0) {
                return (int) $target;
            }
        }

        return 3306;
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->app instanceof App && $target->app->node instanceof Node) {
            return $target->app->node;
        }

        if ($target->workspace instanceof Workspace && $target->workspace->app instanceof App && $target->workspace->app->node instanceof Node) {
            return $target->workspace->app->node;
        }

        throw new \RuntimeException('Database connection target has no owning node.');
    }
}
