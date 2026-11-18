<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Models\DatabaseConnection;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * The Redis server of the Gateway queue (docs/reference/gateway-queue.md): the registered Redis Database connection
 * that `ORBIT_GATEWAY_QUEUE_CONNECTION` names, with the database index `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE`. Orbit's
 * records own the host and port, so the queue follows them when the server moves.
 */
final class GatewayQueueRedis
{
    public const string KeyPrefix = 'orbit-gateway:';

    /** The host config/database.php holds until configure() runs. */
    public const string UnconfiguredHost = 'orbit-gateway-queue-unconfigured.invalid';

    public static function configured(?Repository $config = null): bool
    {
        return self::slug($config ?? app('config')) !== '';
    }

    /** Whether this configuration already holds the registered host. */
    public static function applied(Repository $config): bool
    {
        return $config->get('database.redis.gateway.host', self::UnconfiguredHost) !== self::UnconfiguredHost;
    }

    /**
     * Fills in the `gateway` Redis connection from the registered connection and points Horizon at it. It runs once,
     * before a process first uses Redis. It writes to the given configuration: under Octane, Redis can resolve on the
     * worker's application while the facades point at the request's configuration copy.
     *
     * @throws RuntimeException when the key is empty or names no Redis connection
     */
    public static function configure(?Repository $config = null): void
    {
        $config ??= app('config');
        $slug = self::slug($config);

        if ($slug === '') {
            throw new RuntimeException('The Gateway queue has no Redis server: ORBIT_GATEWAY_QUEUE_CONNECTION is empty. See /reference/gateway-queue.');
        }

        $connection = DatabaseConnection::query()->where('slug', $slug)->first();

        if (! $connection instanceof DatabaseConnection || $connection->driver !== DatabaseDriver::Redis || ! is_string($connection->host) || $connection->host === '') {
            throw new RuntimeException("ORBIT_GATEWAY_QUEUE_CONNECTION names [{$slug}], which is not a registered Redis Database connection with a host.");
        }

        // Let Valkey return its BLPOP timeout before PHP gives up on the socket. Predis disconnects
        // on transport timeouts, so a late reply cannot be consumed by the next worker request.
        $socketTimeout = (int) ini_get('default_socket_timeout');
        $gateway = [
            'host' => $connection->host,
            'port' => $connection->port ?? 6379,
            'username' => $connection->username,
            'password' => $connection->password,
            'database' => $config->integer('orbit.gateway_queue.redis_database', 1),
            'options' => ['prefix' => self::KeyPrefix],
            'read_write_timeout' => $socketTimeout <= 0 ? -1 : max($socketTimeout, ExecutorResultWait::boundSeconds() + 1),
        ];
        $config->set('database.redis.gateway', $gateway);
        // What Horizon::use('gateway') does, on this configuration instead of the global one.
        $prefix = $config->get('horizon.prefix') ?: 'horizon:';
        $config->set('horizon.prefix', $prefix);
        $config->set('database.redis.horizon', ['options' => ['prefix' => $prefix]] + $gateway);
    }

    private static function slug(Repository $config): string
    {
        $slug = $config->get('orbit.gateway_queue.connection');

        return is_string($slug) ? trim($slug) : '';
    }
}
