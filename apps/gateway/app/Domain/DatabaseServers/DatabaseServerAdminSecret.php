<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseServer;

/**
 * The server's own vault keys for its admin password (R3). Every database kind that logs in as the admin reads them
 * by name; the password never enters an intent, a run input or an argument.
 */
final readonly class DatabaseServerAdminSecret
{
    public const string KEY_PATTERN = '^database-server-[1-9][0-9]*-(client-env|first-env)$';

    public const array EXECUTION_COPY = ['vault' => 'orbit-control-inputs', 'key_pattern' => self::KEY_PATTERN];

    /**
     * The first-start password as the Docker env file MySQL or PostgreSQL reads once on an empty data volume. A step
     * writes it with cfgmgmt/file (0600), `docker run --env-file` reads it, and an always-run step removes it.
     *
     * @return array{vault: string, key: string}
     */
    public static function firstEnvCopy(DatabaseServer $server): array
    {
        $variable = DatabaseServerProcess::passwordVariable($server);

        return app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', "database-server-{$server->id}-first-env", "{$variable}={$server->root_password}\n");
    }

    /**
     * The admin login as a Docker env file, for clients run with `docker exec --env-file` (Q-DBAUTH). A step writes it
     * with cfgmgmt/file (0600) and removes it at the end. PostgreSQL statements use local trust; its TCP login check
     * reads PGPASSWORD.
     *
     * @return array{vault: string, key: string}
     */
    public static function clientEnvCopy(DatabaseServer $server): array
    {
        $variable = match ($server->driver) {
            DatabaseDriver::Pgsql => 'PGPASSWORD', DatabaseDriver::Redis => 'REDISCLI_AUTH', default => 'MYSQL_PWD'};

        return app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', "database-server-{$server->id}-client-env", "{$variable}={$server->root_password}\n");
    }
}
