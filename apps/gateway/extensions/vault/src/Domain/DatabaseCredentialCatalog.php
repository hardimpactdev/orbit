<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\DatabaseConnection;

/**
 * Builds the Database items the Gateway wants in Vaultwarden from the stored connections and
 * Database users. Fields follow schema version 1 of Vault's Database item.
 */
final readonly class DatabaseCredentialCatalog
{
    public const string Notes = 'Managed by Orbit. Orbit overwrites changes made here and deletes this item when the database or user is removed.';

    /** @return array{items: array<string, DatabaseCredentialItem>, skipped: list<SkippedCredential>} */
    public function build(): array
    {
        $items = [];
        $skipped = [];

        $connections = DatabaseConnection::query()
            ->whereIn('driver', [DatabaseDriver::Mysql->value, DatabaseDriver::Pgsql->value])
            ->with('users')
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            $source = 'database-connection:'.$connection->id;
            $endpoint = $this->endpoint($connection);

            if ($endpoint === null || ! $this->filled($connection->username) || ! $this->filled($connection->password)) {
                $skipped[] = new SkippedCredential($source, $connection->slug, $connection->username, SkippedCredential::Incomplete);
            } else {
                $items[$source] = new DatabaseCredentialItem($source, $this->cipher(
                    $connection->slug,
                    $endpoint,
                    $connection->username,
                    $connection->password,
                    $source,
                ));
            }

            foreach ($connection->users->sortBy('id') as $user) {
                if ($user->username === $connection->username) {
                    continue;
                }

                $userSource = 'database-user:'.$user->id;

                if (! $this->filled($user->password)) {
                    $skipped[] = new SkippedCredential($userSource, $connection->slug, $user->username, SkippedCredential::PasswordUnknown);

                    continue;
                }

                if ($endpoint === null) {
                    $skipped[] = new SkippedCredential($userSource, $connection->slug, $user->username, SkippedCredential::Incomplete);

                    continue;
                }

                $items[$userSource] = new DatabaseCredentialItem($userSource, $this->cipher(
                    "{$connection->slug} ({$user->username})",
                    $endpoint,
                    $user->username,
                    $user->password,
                    $userSource,
                ));
            }
        }

        return ['items' => $items, 'skipped' => $skipped];
    }

    /** @return array{driver: string, host: string, port: int, database: string}|null */
    private function endpoint(DatabaseConnection $connection): ?array
    {
        if (! $this->filled($connection->host) || ! $this->filled($connection->database)) {
            return null;
        }

        return [
            'driver' => $connection->driver === DatabaseDriver::Pgsql ? 'postgresql' : 'mysql',
            'host' => $connection->host,
            'port' => $connection->port ?? $connection->driver->defaultPort() ?? 0,
            'database' => $connection->database,
        ];
    }

    /** @param array{driver: string, host: string, port: int, database: string} $endpoint */
    private function cipher(string $name, array $endpoint, string $username, string $password, string $source): VaultCipher
    {
        $field = static fn (string $name, string $value, bool $hidden = false): array => ['name' => $name, 'value' => $value, 'hidden' => $hidden];

        return new VaultCipher($name, self::Notes, [
            $field('vault.schema', '1'),
            $field('type', 'database'),
            $field('driver', $endpoint['driver']),
            $field('host', $endpoint['host']),
            $field('port', (string) $endpoint['port']),
            $field('database', $endpoint['database']),
            $field('username', $username),
            $field('password', $password, true),
            $field('tls', 'disabled'),
            $field('orbit.source', $source),
        ]);
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }
}
