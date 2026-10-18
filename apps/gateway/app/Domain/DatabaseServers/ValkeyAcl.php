<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use LogicException;

/**
 * The ACL users that Orbit records for a Valkey Database server, and how the live users differ from them. Doctor
 * reports the difference, `database:server:create` on an active server removes it, and the ACL file holds the same
 * users, so the three never disagree.
 */
final readonly class ValkeyAcl
{
    /**
     * The recorded users by name: `default` with the admin password and every right, and one user per connection on
     * the server that logs in with its own username. An open server has no recorded `default` until it is closed.
     *
     * @return array<string, ValkeyAclUser>
     */
    public static function users(DatabaseServer $server): array
    {
        $users = $server->default_open
            ? []
            : [ValkeyAclUser::ADMIN => ValkeyAclUser::withPassword(ValkeyAclUser::ADMIN, ValkeyAclUser::ADMIN_RULES, $server->root_password)];

        foreach ($server->databaseConnections as $connection) {
            $username = $connection->username;

            if ($username === null || $username === '' || $username === ValkeyAclUser::ADMIN) {
                continue;
            }

            $users[$username] = self::connectionUser($connection, $username);
        }

        return $users;
    }

    /**
     * What makes the live users the recorded users: each recorded user that is missing or differs, and each other
     * live user. An open server keeps its live `default`.
     *
     * @param  array<string, ValkeyAclUser>  $live
     * @return array{set: list<ValkeyAclUser>, delete: list<string>}
     */
    public static function changes(DatabaseServer $server, array $live): array
    {
        $recorded = self::users($server);

        if ($server->default_open) {
            unset($live[ValkeyAclUser::ADMIN]);
        }

        $set = array_values(array_filter(
            $recorded,
            static fn (ValkeyAclUser $user): bool => ! ($live[$user->name] ?? null)?->sameAs($user),
        ));

        return ['set' => $set, 'delete' => array_keys(array_diff_key($live, $recorded))];
    }

    /**
     * The ACL file `/data/users.acl`: `default` with the hash of the admin password, or `nopass` on an open server,
     * then one line per recorded user, `user <name> #<hash> <rules>`. It holds no password.
     */
    public static function file(DatabaseServer $server): string
    {
        $lines = ['user default on '.($server->default_open ? 'nopass' : '#'.ValkeyAclUser::passwordHash($server->root_password)).' ~* &* +@all'];

        foreach (self::users($server) as $user) {
            if ($user->name !== ValkeyAclUser::ADMIN) {
                $hashes = implode(' ', array_map(static fn (string $hash): string => '#'.$hash, $user->passwordHashes));
                $lines[] = "user {$user->name} {$hashes} {$user->rules}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function connectionUser(DatabaseConnection $connection, string $username): ValkeyAclUser
    {
        $rules = $connection->users->firstWhere('username', $username)?->privileges;

        if (! is_string($rules) || ! is_string($connection->password)) {
            throw new LogicException("Connection [{$connection->slug}] has no recorded ACL user.");
        }

        return ValkeyAclUser::withPassword($username, $rules, $connection->password);
    }
}
