<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Commands\OrbitCommand;
use App\Exceptions\OperationTokenGuardException;
use App\Services\Executor\OperationTokenGuard;
use PDO;
use PDOException;
use PDOStatement;

final class WgEasyStateCommand extends OrbitCommand
{
    private const ActionUpdateUser = 'update-user';

    private const ActionUpdateGeneral = 'update-general';

    private const ActionEnsureWritable = 'ensure-writable';

    private const ActionConfigurePeers = 'configure-peers';

    /**
     * @var list<string>
     */
    private const Actions = [
        self::ActionUpdateUser,
        self::ActionUpdateGeneral,
        self::ActionEnsureWritable,
        self::ActionConfigurePeers,
    ];

    protected $signature = 'internal:wg-easy:state
        {--action=}
        {--operation-token=}
        {--json}
        {--host=}
        {--default-dns=}
        {--default-persistent-keepalive=}
        {--setup-step=}
        {--database-path=}
        {--peers-json=}';

    protected $description = 'Update wg-easy state through the internal local executor';

    public function handle(OperationTokenGuard $guard): int
    {
        $compactToken = $this->option('operation-token');

        if (! is_string($compactToken) || trim($compactToken) === '') {
            return $this->renderFailure('missing_token', 'Operation token is required.', []);
        }

        try {
            $guard->verify($compactToken, 'internal:wg-easy:state');
        } catch (OperationTokenGuardException) {
            return $this->renderFailure('invalid_token', 'Operation token is invalid.', []);
        }

        $action = $this->stringValue($this->option('action'));

        if ($action === null) {
            return $this->invalidOption('action');
        }

        if (! in_array($action, self::Actions, true)) {
            return $this->renderFailure(
                'invalid_action',
                'wg-easy state action must be one of: update-user, update-general, ensure-writable, configure-peers.',
                [
                    'action' => $action,
                    'allowed' => self::Actions,
                ],
            );
        }

        return match ($action) {
            self::ActionUpdateUser => $this->updateUser(),
            self::ActionUpdateGeneral => $this->updateGeneral(),
            self::ActionEnsureWritable => $this->ensureWritable(),
            self::ActionConfigurePeers => $this->configurePeers(),
        };
    }

    private function updateUser(): int
    {
        $host = $this->stringValue($this->option('host'));

        if ($host === null) {
            return $this->invalidOption('host');
        }

        $defaultDns = $this->stringValue($this->option('default-dns'));

        if ($defaultDns === null) {
            return $this->invalidOption('default-dns');
        }

        $defaultPersistentKeepalive = $this->integerValue($this->option('default-persistent-keepalive'));

        if ($defaultPersistentKeepalive === null) {
            return $this->invalidOption('default-persistent-keepalive');
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $this->prepare($database, <<<'SQL'
                update user_configs_table
                set host = :host,
                    default_dns = :default_dns,
                    default_persistent_keepalive = :default_persistent_keepalive
                SQL)->execute([
                'host' => $host,
                'default_dns' => $defaultDns,
                'default_persistent_keepalive' => $defaultPersistentKeepalive,
            ]);
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpdateUser],
            );
        }

        return $this->renderSuccess([
            'action' => self::ActionUpdateUser,
            'updated' => true,
        ], []);
    }

    private function updateGeneral(): int
    {
        $setupStep = $this->integerValue($this->option('setup-step'));

        if ($setupStep === null) {
            return $this->invalidOption('setup-step');
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $this->prepare($database, <<<'SQL'
                update general_table
                set setup_step = :setup_step
                SQL)->execute([
                'setup_step' => $setupStep,
            ]);
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpdateGeneral],
            );
        }

        return $this->renderSuccess([
            'action' => self::ActionUpdateGeneral,
            'updated' => true,
        ], []);
    }

    private function configurePeers(): int
    {
        $peers = $this->peersPayload();

        if (is_int($peers)) {
            return $peers;
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $database->beginTransaction();

            foreach ($peers as $peer) {
                $this->prepare($database, <<<'SQL'
                    delete from clients_table
                    where name = :name
                       or public_key = :public_key
                       or ipv4_address = :ipv4_address
                    SQL)->execute([
                    'name' => $peer['name'],
                    'public_key' => $peer['public_key'],
                    'ipv4_address' => $peer['address'],
                ]);

                $this->prepare($database, <<<'SQL'
                    insert into clients_table (
                        user_id,
                        interface_id,
                        name,
                        ipv4_address,
                        ipv6_address,
                        private_key,
                        public_key,
                        pre_shared_key,
                        allowed_ips,
                        server_allowed_ips,
                        persistent_keepalive,
                        mtu,
                        dns,
                        enabled
                    ) values (
                        1,
                        'wg0',
                        :name,
                        :ipv4_address,
                        :ipv6_address,
                        :private_key,
                        :public_key,
                        :pre_shared_key,
                        :allowed_ips,
                        :server_allowed_ips,
                        25,
                        1420,
                        :dns,
                        1
                    )
                    SQL)->execute([
                    'name' => $peer['name'],
                    'ipv4_address' => $peer['address'],
                    'ipv6_address' => $this->ipv6For($peer['address']),
                    'private_key' => $peer['private_key'],
                    'public_key' => $peer['public_key'],
                    'pre_shared_key' => $peer['pre_shared_key'],
                    'allowed_ips' => '["0.0.0.0/0", "::/0"]',
                    'server_allowed_ips' => '["'.$peer['address'].'/32"]',
                    'dns' => '["10.6.0.1"]',
                ]);
            }

            $database->commit();
        } catch (PDOException) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionConfigurePeers],
            );
        }

        return $this->renderSuccess([
            'action' => self::ActionConfigurePeers,
            'configured' => count($peers),
        ], []);
    }

    private function ensureWritable(): int
    {
        $path = $this->databasePath();

        if (is_int($path)) {
            return $path;
        }

        if ($path === null) {
            return $this->renderFailure(
                'home_directory_unavailable',
                'Home directory could not be resolved for wg-easy state.',
                [],
            );
        }

        if (! is_file($path)) {
            return $this->renderFailure(
                'database_missing',
                'wg-easy database does not exist.',
                [],
            );
        }

        clearstatcache(true, $path);

        if (is_writable($path)) {
            return $this->renderSuccess([
                'action' => self::ActionEnsureWritable,
                'writable' => true,
                'ownership_changed' => false,
            ], []);
        }

        if (! $this->pathMayBeChowned($path)) {
            return $this->renderFailure(
                'database_unwritable',
                'wg-easy database is not writable.',
                ['reason' => 'path_not_chown_eligible'],
            );
        }

        if (! $this->chownToCurrentUser($path)) {
            return $this->renderFailure(
                'database_unwritable',
                'wg-easy database is not writable.',
                ['reason' => 'chown_failed'],
            );
        }

        clearstatcache(true, $path);

        if (! is_writable($path)) {
            return $this->renderFailure(
                'database_unwritable',
                'wg-easy database is not writable.',
                ['reason' => 'chown_failed'],
            );
        }

        return $this->renderSuccess([
            'action' => self::ActionEnsureWritable,
            'writable' => true,
            'ownership_changed' => true,
        ], []);
    }

    private function openWritableDatabase(): PDO|int
    {
        $path = $this->databasePath();

        if (is_int($path)) {
            return $path;
        }

        if ($path === null) {
            return $this->renderFailure(
                'home_directory_unavailable',
                'Home directory could not be resolved for wg-easy state.',
                [],
            );
        }

        if (! is_file($path)) {
            return $this->renderFailure(
                'database_missing',
                'wg-easy database does not exist.',
                [],
            );
        }

        if (! is_writable($path)) {
            return $this->renderFailure(
                'database_unwritable',
                'wg-easy database is not writable.',
                [],
            );
        }

        try {
            return new PDO("sqlite:{$path}", null, null, $this->sqliteOptions());
        } catch (PDOException) {
            return $this->renderFailure(
                'database_unwritable',
                'wg-easy database could not be opened for writing.',
                [],
            );
        }
    }

    private function prepare(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->prepare($sql);

        if (! $statement instanceof PDOStatement) {
            throw new PDOException('Statement could not be prepared.');
        }

        return $statement;
    }

    /**
     * @return array<int, mixed>
     */
    private function sqliteOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    private function databasePath(): string|int|null
    {
        $option = $this->option('database-path');

        if (is_string($option) && $option !== '') {
            $path = $this->stringValue($option);

            if ($path === null) {
                return $this->renderFailure(
                    'validation_failed',
                    'The --database-path option is invalid.',
                    ['field' => 'database-path'],
                );
            }

            return $path;
        }

        $override = $this->rawEnvironmentValue('ORBIT_WG_EASY_DB_PATH');

        if (is_string($override)) {
            $path = $this->stringValue($override);

            if ($path === null) {
                return $this->renderFailure(
                    'validation_failed',
                    'The ORBIT_WG_EASY_DB_PATH environment value is invalid.',
                    ['field' => 'ORBIT_WG_EASY_DB_PATH'],
                );
            }

            return $path;
        }

        $home = $this->homeDirectory();

        return $home === null ? null : "{$home}/.wg-easy/wg-easy.db";
    }

    private function pathMayBeChowned(string $path): bool
    {
        $home = $this->homeDirectory();

        if ($home === null) {
            return false;
        }

        return $this->normalizePath($path) === "{$home}/.wg-easy/wg-easy.db";
    }

    private function chownToCurrentUser(string $path): bool
    {
        if (! function_exists('posix_getuid') || ! function_exists('posix_getgid')) {
            return false;
        }

        return @chown($path, posix_getuid())
            && @chgrp($path, posix_getgid());
    }

    private function invalidOption(string $field): int
    {
        return $this->renderFailure(
            'validation_failed',
            "The --{$field} option is invalid.",
            ['field' => $field],
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, "\0")) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value < 0 ? null : $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        if (
            strlen($value) > strlen((string) PHP_INT_MAX)
            || (
                strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0
            )
        ) {
            return null;
        }

        return (int) $value;
    }

    private function environmentPath(string $key): ?string
    {
        $value = $this->rawEnvironmentValue($key);

        return $this->stringValue($value);
    }

    private function rawEnvironmentValue(string $key): ?string
    {
        $value = getenv($key);

        if (is_string($value)) {
            return $value;
        }

        $value = $_SERVER[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        $value = $_ENV[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function homeDirectory(): ?string
    {
        $home = $this->environmentPath('HOME');

        if ($home !== null) {
            return rtrim($home, '/');
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $entry = posix_getpwuid(posix_getuid());

            if (is_array($entry)) {
                return $this->stringValue($entry['dir'] ?? null);
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return rtrim($path, '/');
    }

    /**
     * @return list<array{name: string, private_key: string, public_key: string, pre_shared_key: string, address: string}>|int
     */
    private function peersPayload(): array|int
    {
        $json = $this->stringValue($this->option('peers-json'));

        if ($json === '-') {
            $json = stream_get_contents(STDIN);
        }

        if ($json === null || $json === false || trim($json) === '') {
            return $this->invalidOption('peers-json');
        }

        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->invalidOption('peers-json');
        }

        if (! is_array($decoded)) {
            return $this->invalidOption('peers-json');
        }

        $peers = [];

        foreach ($decoded as $peer) {
            if (! is_array($peer)) {
                return $this->invalidOption('peers-json');
            }

            $normalized = $this->peerPayload($peer);

            if ($normalized === null) {
                return $this->invalidOption('peers-json');
            }

            $peers[] = $normalized;
        }

        return $peers;
    }

    /**
     * @param  array<mixed>  $peer
     * @return array{name: string, private_key: string, public_key: string, pre_shared_key: string, address: string}|null
     */
    private function peerPayload(array $peer): ?array
    {
        $name = $this->stringValue($peer['name'] ?? null);
        $privateKey = $this->stringValue($peer['private_key'] ?? null);
        $publicKey = $this->stringValue($peer['public_key'] ?? null);
        $preSharedKey = $this->stringValue($peer['pre_shared_key'] ?? null);
        $address = $this->stringValue($peer['address'] ?? null);

        if ($name === null || $privateKey === null || $publicKey === null || $preSharedKey === null || $address === null) {
            return null;
        }

        return [
            'name' => $name,
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'pre_shared_key' => $preSharedKey,
            'address' => $address,
        ];
    }

    private function ipv6For(string $ipv4): string
    {
        $lastOctet = (int) substr(strrchr($ipv4, '.') ?: '.0', 1);

        return 'fdcc:ad94:bacf:61a4::cafe:'.dechex($lastOctet);
    }
}
