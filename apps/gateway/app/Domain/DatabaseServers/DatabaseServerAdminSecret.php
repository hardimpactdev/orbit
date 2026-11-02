<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseServer;

/**
 * The server's own vault key for its admin password (R3). Every database kind that logs in as the admin reads this
 * key by name; the password never enters an intent, a run input or an argument.
 */
final readonly class DatabaseServerAdminSecret
{
    public const string KEY_PATTERN = '^database-server-[1-9][0-9]*-(admin|client-env)$';

    public const array EXECUTION_COPY = ['vault' => 'orbit-control-inputs', 'key_pattern' => self::KEY_PATTERN];

    /** @return array{vault: string, key: string} */
    public static function copy(DatabaseServer $server): array
    {
        return app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', "database-server-{$server->id}-admin", $server->root_password);
    }

    /**
     * The admin login as a Docker env file, for MySQL and Valkey clients run with `docker exec --env-file` (Q-DBAUTH).
     * A step writes it with cfgmgmt/file (0600) and removes it at the end; PostgreSQL uses local trust and has none.
     *
     * @return array{vault: string, key: string}
     */
    public static function clientEnvCopy(DatabaseServer $server): array
    {
        $variable = $server->driver === DatabaseDriver::Redis ? 'REDISCLI_AUTH' : 'MYSQL_PWD';

        return app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', "database-server-{$server->id}-client-env", "{$variable}={$server->root_password}\n");
    }
}
