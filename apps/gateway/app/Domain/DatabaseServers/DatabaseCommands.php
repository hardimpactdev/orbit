<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseServer;
use SensitiveParameter;

/** Records protected Valkey intent for native execution.
 * @phpstan-import-type Command from \App\Models\DatabaseExecutionIntent
 */
final readonly class DatabaseCommands
{
    /** @param array<string, mixed> $payload
     * @return Command
     */
    public static function valkey(DatabaseServer $server, string $action, #[SensitiveParameter] array $payload = [], string $backend = 'active'): array
    {
        return ['transport' => 'remote_program', 'name' => 'database.valkey', 'input' => [
            'host' => $server->node->wireguard_ip, 'port' => $server->port,
            'password' => $server->root_password, 'default_open' => $server->default_open, 'action' => $action, 'payload' => (object) $payload,
        ], 'protected' => ['password', 'payload'], 'backend' => $backend, 'redactions' => [$server->root_password]];
    }
}
