<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Enums\Processes\ProcessRuntime;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Eloquent\Builder;

final readonly class ManagedDockerMysqlEndpointResolver
{
    /**
     * @return array{host: string, port: int}|null
     */
    public function resolve(DatabaseConnection $connection, Node $targetNode): ?array
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

        return [
            'host' => $process->name,
            'port' => $this->managedMysqlTargetPort($process),
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
            ->where(static function (Builder $query): void {
                $query
                    ->where('runtime_config->service', 'mysql')
                    ->orWhere('runtime_config->definition', 'mysql');
            })
            ->get();

        foreach ($processes as $process) {
            if ($this->processMatchesConnection($process, $connection)) {
                return $process;
            }
        }

        return null;
    }

    private function processMatchesConnection(Process $process, DatabaseConnection $connection): bool
    {
        $endpoint = is_array($process->runtime_config['endpoint'] ?? null)
            ? $process->runtime_config['endpoint']
            : [];

        $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';
        $port = $endpoint['port'] ?? null;

        return (
            $host === trim((string) $connection->host)
            && is_numeric($port)
            && (int) $port === (int) $connection->port
        );
    }

    private function managedMysqlTargetPort(Process $process): int
    {
        $ports = is_array($process->runtime_config['ports'] ?? null)
            ? $process->runtime_config['ports']
            : [];

        foreach ($ports as $port) {
            $target = is_array($port) ? $port['target'] ?? null : null;

            if (is_int($target) && $target > 0) {
                return $target;
            }

            if (is_numeric($target) && (int) $target > 0) {
                return (int) $target;
            }
        }

        return 3306;
    }
}
