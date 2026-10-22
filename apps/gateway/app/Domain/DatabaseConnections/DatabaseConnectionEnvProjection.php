<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;

/**
 * Prefixed connection keys. Explicit LAN selection requires active Nodes with IPv4 LAN addresses;
 * the default keeps the connection's recorded host and port.
 */
final readonly class DatabaseConnectionEnvProjection
{
    /** @var list<string> */
    public const array Suffixes = [
        'CONNECTION',
        'HOST',
        'PORT',
        'DATABASE',
        'USERNAME',
        'PASSWORD',
    ];

    /**
     * @return array{
     *     values: array<string, string>,
     *     forget: list<string>,
     *     keys: list<string>,
     *     host: string|null,
     *     port: int|null
     * }
     */
    public function project(DatabaseConnection $connection, string $prefix, string $network = 'wireguard', ?Node $consumer = null): array
    {
        $managed = $this->managedKeys($prefix);
        $values = [$this->key($prefix, 'CONNECTION') => $connection->driver->value];

        if ($connection->driver === DatabaseDriver::Sqlite) {
            $values[$this->key($prefix, 'DATABASE')] = (string) $connection->path;

            if (is_string($connection->username)) {
                $values[$this->key($prefix, 'USERNAME')] = $connection->username;
            }

            if (is_string($connection->password)) {
                $values[$this->key($prefix, 'PASSWORD')] = $connection->password;
            }

            return $this->result($values, $managed, host: null, port: null);
        }

        $host = (string) $connection->host;
        $server = $connection->server;
        if ($network === 'lan') {
            if (! $server instanceof DatabaseServer || ! $consumer instanceof Node || $server->node->status !== LifecycleStatus::Active || $consumer->status !== LifecycleStatus::Active || filter_var($server->node->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || filter_var($consumer->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('database.lan_unavailable', 'Both Nodes must be active with an IPv4 LAN address.', 422);
            }
            $host = (string) $server->node->lan_ip;
        } elseif ($network !== 'wireguard') {
            throw new ResourceOperationException('validation.failed', 'The network must be wireguard or lan.', 422);
        }
        $port = (int) $connection->port;
        $values[$this->key($prefix, 'HOST')] = $host;
        $values[$this->key($prefix, 'PORT')] = (string) $port;

        if (! $connection->driver->requiresCredentials()) {
            if (is_string($connection->database)) {
                $values[$this->key($prefix, 'DATABASE')] = $connection->database;
            }

            if (is_string($connection->username)) {
                $values[$this->key($prefix, 'USERNAME')] = $connection->username;
            }

            if (is_string($connection->password)) {
                $values[$this->key($prefix, 'PASSWORD')] = $connection->password;
            }

            return $this->result($values, $managed, $host, $port);
        }

        $values[$this->key($prefix, 'DATABASE')] = (string) $connection->database;
        $values[$this->key($prefix, 'USERNAME')] = (string) $connection->username;
        $values[$this->key($prefix, 'PASSWORD')] = (string) $connection->password;

        return $this->result($values, $managed, $host, $port);
    }

    /** @return list<string> */
    public function managedKeys(string $prefix): array
    {
        return array_map(
            fn (string $suffix): string => $this->key($prefix, $suffix),
            self::Suffixes,
        );
    }

    public function key(string $prefix, string $suffix): string
    {
        return $prefix.'_'.$suffix;
    }

    /**
     * @param  array<string, string>  $stored
     * @param  array{
     *     values: array<string, string>,
     *     forget: list<string>,
     *     keys: list<string>,
     *     host: string|null,
     *     port: int|null
     * }  $projected
     * @return list<string>
     */
    public function driftedKeys(array $stored, array $projected): array
    {
        $drifted = [];

        foreach ($projected['values'] as $key => $value) {
            if (! array_key_exists($key, $stored) || $stored[$key] !== $value) {
                $drifted[] = $key;
            }
        }

        foreach ($projected['forget'] as $key) {
            if (array_key_exists($key, $stored)) {
                $drifted[] = $key;
            }
        }

        sort($drifted);

        return $drifted;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $managed
     * @return array{
     *     values: array<string, string>,
     *     forget: list<string>,
     *     keys: list<string>,
     *     host: string|null,
     *     port: int|null
     * }
     */
    private function result(array $values, array $managed, ?string $host, ?int $port): array
    {
        $keys = array_keys($values);
        sort($keys);

        return [
            'values' => $values,
            'forget' => array_values(array_diff($managed, $keys)),
            'keys' => $keys,
            'host' => $host,
            'port' => $port,
        ];
    }
}
