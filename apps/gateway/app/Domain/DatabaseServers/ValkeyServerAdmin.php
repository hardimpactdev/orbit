<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseServer;

/**
 * Runs admin commands on a Valkey Database server. The Gateway connects to the Node's WireGuard address and the
 * server's port, as every client does, and logs in as `default` with the admin password. A user's password reaches
 * Valkey only as its SHA-256 hash. A rule that Valkey refuses throws `database.acl_rules_invalid` with Valkey's message;
 * every other failure throws `database.server_command_failed` without output.
 */
interface ValkeyServerAdmin
{
    /** Whether `default` logs in with the admin password now. A connection that fails is false. */
    public function acceptsAdminLogin(DatabaseServer $server): bool;

    /** The configured database count; valid indexes are zero through count minus one. */
    public function databaseCount(DatabaseServer $server): int;

    /**
     * Effective configuration that adoption must preserve: dir, appendonly, appenddirname, appendfilename,
     * dbfilename, and databases.
     *
     * @return array<string, string>
     */
    public function adoptionConfiguration(DatabaseServer $server): array;

    public function userExists(DatabaseServer $server, string $username): bool;

    /**
     * The live users, keyed by name.
     *
     * @return array<string, ValkeyAclUser>
     */
    public function aclUsers(DatabaseServer $server): array;

    /** The ACL file that Valkey runs with, from `CONFIG GET aclfile`, or null without one. */
    public function aclFile(DatabaseServer $server): ?string;

    /** Whether a client without a password can run a command. Only a `NOAUTH` reply to `PING` is false. */
    public function acceptsAnonymous(DatabaseServer $server): bool;

    /** Retained removal deletes only an unshared ACL user and saves the ACL file. */
    public function deleteUser(DatabaseServer $server, string $username): void;
}
