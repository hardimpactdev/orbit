<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Config;
use Laravel\Horizon\Horizon;
use RuntimeException;

/**
 * The Redis server of the Gateway queue (docs/reference/gateway-queue.md): the registered Redis Database connection
 * that `ORBIT_GATEWAY_QUEUE_CONNECTION` names, with the database index `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE`. Orbit's
 * records own the host and port, so the queue follows them when the server moves.
 */
final class GatewayQueueRedis
{
    public const string KeyPrefix = 'orbit-gateway:';

    public static function configured(): bool
    {
        return self::slug() !== '';
    }

    /**
     * Fills in the `gateway` Redis connection from the registered connection and points Horizon at it. It runs once,
     * before a process first uses Redis.
     *
     * @throws RuntimeException when the key is empty or names no Redis connection
     */
    public static function configure(): void
    {
        $slug = self::slug();

        if ($slug === '') {
            throw new RuntimeException('The Gateway queue has no Redis server: ORBIT_GATEWAY_QUEUE_CONNECTION is empty. See /reference/gateway-queue.');
        }

        $connection = DatabaseConnection::query()->where('slug', $slug)->first();

        if (! $connection instanceof DatabaseConnection || $connection->driver !== DatabaseDriver::Redis || ! is_string($connection->host) || $connection->host === '') {
            throw new RuntimeException("ORBIT_GATEWAY_QUEUE_CONNECTION names [{$slug}], which is not a registered Redis Database connection with a host.");
        }

        Config::set('database.redis.gateway', [
            'host' => $connection->host,
            'port' => $connection->port ?? 6379,
            'username' => $connection->username,
            'password' => $connection->password,
            'database' => Config::integer('orbit.gateway_queue.redis_database', 1),
            'options' => ['prefix' => self::KeyPrefix],
        ]);
        Horizon::use('gateway');
    }

    private static function slug(): string
    {
        $slug = Config::get('orbit.gateway_queue.connection');

        return is_string($slug) ? trim($slug) : '';
    }
}
