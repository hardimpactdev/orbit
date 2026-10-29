<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseServer;

/**
 * Runs admin commands as MySQL root inside a Database server's container. The root password
 * travels only on protected standard input. A failed command throws
 * `database.server_command_failed` without SQL output.
 */
interface DatabaseServerAdmin
{
    /** Wait until MySQL accepts the root login over TCP inside the container. */
    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool;

    /**
     * Run SQL statements and return the first column of each result row.
     *
     * @return list<string>
     */
    public function execute(DatabaseServer $server, string $sql): array;
}
