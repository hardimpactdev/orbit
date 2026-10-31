<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;

/**
 * The checks before `database:server:create --process --connection` adopts an existing Valkey Process (ADR 0221). A
 * failed check refuses with `database.server_adoption_refused`, names the check, and changes nothing. The option exists
 * only to adopt the Valkey Processes on `services` and `beast`.
 */
final readonly class ValkeyProcessAdoption
{
    /**
     * Admit recorded Process ownership; the Node checks authentication and live state.
     *
     * @return array{process: Process, connection: DatabaseConnection, port: int}
     */
    public function admit(Node $node, string $slug, int $processId, string $connectionSlug): array
    {
        $process = Process::query()->find($processId);

        if (
            ! $process instanceof Process
            || $process->owner_type !== Node::class
            || $process->owner_id !== $node->id
            || $process->runtime !== ProcessRuntime::Docker
        ) {
            throw self::refused("Process [{$processId}] is not a Docker Process of Node [{$node->name}].");
        }

        $image = $process->runtime_config['image'] ?? null;

        if (! is_string($image) || preg_match('/\Avalkey\/valkey(?:[:@][^\s]+)?\z/D', $image) !== 1) {
            throw self::refused("Process [{$process->name}] does not run a valkey/valkey image.");
        }

        if (DatabaseServerProcess::valkeyVolume($process) === null) {
            throw self::refused("Process [{$process->name}] has no Docker volume at /data.");
        }

        $port = $this->publishedPort($process, $node);

        if ($port === null) {
            throw self::refused("Process [{$process->name}] does not publish container port 6379 on the WireGuard address of Node [{$node->name}].");
        }

        $existing = DatabaseServer::query()->where('process_id', $process->id)->first();
        if ($existing !== null && ($existing->slug !== $slug || $existing->node_id !== $node->id || $existing->driver !== DatabaseDriver::Redis)) {
            throw self::refused("Process [{$process->name}] already runs Database server [{$existing->slug}].");
        }

        $connection = DatabaseConnection::query()->where('slug', $connectionSlug)->first();

        if (! $connection instanceof DatabaseConnection || $connection->driver !== DatabaseDriver::Redis) {
            throw self::refused("Database connection [{$connectionSlug}] is not a redis connection.");
        }

        if ($connection->host !== $node->wireguard_ip || ($connection->port ?? DatabaseDriver::Redis->defaultPort()) !== $port) {
            throw self::refused("Database connection [{$connectionSlug}] does not point to {$node->wireguard_ip}:{$port}.");
        }

        if (($connection->database_server_id !== null && $connection->database_server_id !== $existing?->id)
            || ($existing !== null && ($existing->default_open ? filled($connection->password) : $connection->password !== $existing->root_password))
            || ($connection->username !== null && $connection->username !== 'default')) {
            throw self::refused('Adoption requires the default-user connection.');
        }

        return ['process' => $process, 'connection' => $connection, 'port' => $port];
    }

    public static function refused(string $message): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_adoption_refused',
            message: $message,
            status: 409,
        );
    }

    /** The host port that publishes container port 6379 on the Node's WireGuard address. */
    private function publishedPort(Process $process, Node $node): ?int
    {
        $ports = $process->runtime_config['ports'] ?? null;

        foreach (is_array($ports) ? $ports : [] as $spec) {
            $mapping = is_string($spec) && ! str_ends_with($spec, '/udp') ? DockerPublishedPort::parse($spec) : null;

            if (
                $mapping instanceof DockerPublishedPort
                && $mapping->bindAddress === $node->wireguard_ip
                && $mapping->containerPort === DatabaseServerProcess::VALKEY_CONTAINER_PORT
            ) {
                return $mapping->publishedPort;
            }
        }

        return null;
    }
}
