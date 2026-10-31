<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;

/**
 * An in-memory Valkey server for tests. `$users` holds the live users by name, and `$passwords` the passwords that log
 * in as `default` at an address, keyed `<host>:<port>`. A server that becomes ready gets `default` from its ACL file.
 * Every call is recorded in `$calls`, without a password.
 */
final class FakeValkeyServerAdmin implements ValkeyServerAdmin
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $passwords = [];

    /** @var array<string, ValkeyAclUser> */
    public array $users = [];

    public ?string $aclFile = '/data/users.acl';

    public bool $anonymous = false;

    public int $databases = 16;

    public bool $failCommands = false;

    public function acceptsAdminLogin(DatabaseServer $server): bool
    {
        $this->calls[] = "login {$server->node->wireguard_ip}:{$server->port}";

        return ($this->passwords["{$server->node->wireguard_ip}:{$server->port}"] ?? null) === $server->root_password;
    }

    public function databaseCount(DatabaseServer $server): int
    {
        $this->command($server, 'config get databases');

        return $this->databases;
    }

    public function userExists(DatabaseServer $server, string $username): bool
    {
        $this->command($server, "exists {$username}");

        return isset($this->users[$username]);
    }

    public function deleteUser(DatabaseServer $server, string $username): void
    {
        $this->command($server, "deluser {$username}");
        unset($this->users[$username]);
    }

    public function aclUsers(DatabaseServer $server): array
    {
        $this->command($server, 'acl list');

        return $this->users;
    }

    public function aclFile(DatabaseServer $server): ?string
    {
        $this->command($server, 'config get aclfile');

        return $this->aclFile;
    }

    public function acceptsAnonymous(DatabaseServer $server): bool
    {
        $this->command($server, 'anonymous ping');

        return $this->anonymous;
    }

    private function command(DatabaseServer $server, string $call): void
    {
        $this->calls[] = $call;

        if ($this->failCommands) {
            throw new ResourceOperationException(
                errorCode: 'database.server_command_failed',
                message: "Database server [{$server->slug}] could not run an admin command.",
                status: 502,
            );
        }
    }
}
