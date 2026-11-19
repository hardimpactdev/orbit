<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;

/** Each attached SQL login reaches its recorded private listener through this guest's loopback only. */
final class ProductionDatabaseEndpoints
{
    /** @param array<string,string> $values
     * @return array{values:array<string,string>,sql:list<array<string,int|string>>}
     */
    public function project(int $instanceId, array $values): array
    {
        $instance = Instance::query()->with('node')->findOrFail($instanceId);
        $targets = DatabaseConnectionTarget::query()->where('instance_id', $instanceId)
            ->with('databaseConnection.server.node', 'databaseConnection.server.process')->orderBy('id')->get();
        $rows = $prefixes = $listeners = [];
        foreach ($targets as $target) {
            $connection = $target->databaseConnection;
            if ($connection->driver === DatabaseDriver::Sqlite) {
                continue;
            }
            if (! in_array($connection->driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql], true)) {
                $this->unavailable();
            }
            $server = $connection->server;
            $port = $connection->port;
            if ($server === null || $server->driver !== $connection->driver || $server->status !== LifecycleStatus::Active
                || $server->node->status !== LifecycleStatus::Active || $port === null || $server->port !== $port
                || $server->process?->runtime !== ProcessRuntime::Docker || $server->process->owner_type !== Node::class
                || $server->process->owner_id !== $server->node_id) {
                $this->unavailable();
            }
            $projected = app(DatabaseConnectionEnvProjection::class)->project($connection, $target->prefix, $target->network, $instance->node)['values'];
            $address = $target->network === 'lan' ? $server->node->lan_ip : $server->node->wireguard_ip;
            $native = $connection->driver === DatabaseDriver::Mysql ? 3306 : 5432;
            $ports = $server->process->runtime_config['ports'] ?? [];
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! preg_match('/^(?:10[.]|192[.]168[.]|172[.](?:1[6-9]|2[0-9]|3[01])[.])/', $address)
                || ! is_array($ports) || ! array_any($ports, static function (mixed $spec) use ($address, $port, $native): bool {
                    $published = is_string($spec) && ! str_ends_with($spec, '/udp') ? DockerPublishedPort::parse($spec) : null;

                    return $published !== null && $published->bindAddress === $address && $published->publishedPort === $port && $published->containerPort === $native;
                })) {
                $this->unavailable();
            }
            $prefix = $target->prefix;
            $host = $projected[$prefix.'_HOST'];
            if ($host !== $address && ! ($server->node_id === $instance->node_id && in_array($host, ['localhost', '127.0.0.1'], true))) {
                $this->unavailable();
            }
            $listen = long2ip(0x7F000000 | ((0x400000 + $target->id) & 0xFFFFFF));
            foreach ($projected as $key => $value) {
                if ($key === $prefix.'_HOST' && ($values[$key] ?? null) === $listen) {
                    continue; // An env:import from this release may already contain its own listener.
                }
                if (($values[$key] ?? null) !== $value) {
                    $this->unavailable();
                }
            }
            if (isset($prefixes[$prefix]) || ! is_string($connection->database) || ! preg_match('/\A[a-zA-Z0-9_$.-]{1,128}\z/D', $connection->database)
                || ! is_string($connection->username) || $connection->username === '' || ! is_string($connection->password) || $connection->password === '') {
                $this->unavailable();
            }
            if (isset($listeners[$listen.':'.$port])) {
                $this->unavailable();
            }
            $listeners[$listen.':'.$port] = $prefixes[$prefix] = true;
            $values[$prefix.'_HOST'] = $listen;
            $rows[] = ['target_id' => $target->id, 'prefix' => $prefix, 'driver' => $connection->driver->value,
                'host' => $address, 'port' => $port, 'listen' => $listen, 'database' => $connection->database,
                'username' => $connection->username, 'password' => $connection->password];
        }

        // A URL or Unix socket can override even a correctly attached host and its credentials.
        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }
            if (in_array($key, ['DATABASE_URL', 'DB_URL', 'DB_SOCKET'], true)
                || preg_match('/^(.+)_(?:URL|SOCKET)$/D', $key, $m) && isset($prefixes[$m[1]])
                || preg_match('/^(.+)_CONNECTION$/D', $key, $m) && in_array($value, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true) && ! isset($prefixes[$m[1]])
                || str_starts_with($key, 'DB_') && ! isset($prefixes['DB']) && ($values['DB_CONNECTION'] ?? null) !== 'sqlite') {
                $this->unavailable();
            }
        }

        return ['values' => $values, 'sql' => $rows];
    }

    /** Credentials are already bound by the rendered environment digest. Keep the route digest independent of them.
     * @param  list<array<string,int|string>>  $rows
     */
    public static function routeDigest(array $rows): string
    {
        $routes = array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'target_id', 'host', 'port', 'listen', 'driver', 'prefix',
        ])), $rows);

        return hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR));
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('deployment.database_endpoint_unconfirmed', 'The production Instance needs an attached SQL login with a verified private server endpoint.', 409);
    }
}
