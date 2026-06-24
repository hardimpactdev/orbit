<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use PDO;
use PDOException;
use PDOStatement;

final class WgEasyStateCommand extends InternalExecutorCommand
{
    private const string ActionUpdateUser = 'update-user';

    private const string ActionUpdateGeneral = 'update-general';

    private const string ActionEnsureWritable = 'ensure-writable';

    private const string ActionUpsertPeer = 'upsert-peer';

    private const string ActionDeletePeer = 'delete-peer';

    private const string ActionUpdateInterface = 'update-interface';

    private const string ActionUpdateUserPassword = 'update-user-password';

    private const string ActionUpdateSessionPassword = 'update-session-password';

    private const string InterfaceName = 'wg0';

    /**
     * @var list<string>
     */
    private const array Actions = [
        self::ActionUpdateUser,
        self::ActionUpdateGeneral,
        self::ActionEnsureWritable,
        self::ActionUpsertPeer,
        self::ActionDeletePeer,
        self::ActionUpdateInterface,
        self::ActionUpdateUserPassword,
        self::ActionUpdateSessionPassword,
    ];

    /**
     * @var list<string>
     */
    private const array PeerIdentityOptions = [
        'name',
        'public-key',
        'ipv4',
    ];

    private const string BcryptPasswordHashPattern = '/\A\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}\z/';

    private const string ArgonPasswordHashPattern = '/\A\$argon2(?:id|i)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+\/=]+\$[A-Za-z0-9+\/=]+\z/';

    private const string InvalidPasswordHashMessage = 'password hash format is not a recognized bcrypt/argon2i/argon2id hash';

    #[\Override]
    protected $signature = 'internal:wg-easy:state
        {--action=}
        {--operation-token=}
        {--json}
        {--host=}
        {--default-dns=}
        {--default-persistent-keepalive=}
        {--setup-step=}
        {--name=}
        {--ipv4=}
        {--ipv6=}
        {--private-key=}
        {--public-key=}
        {--pre-shared-key=}
        {--interface=}
        {--ipv4-cidr=}
        {--password-hash=}';

    #[\Override]
    protected $description = 'Update wg-easy state through the internal local executor';

    public function handle(): int
    {
        if (! $this->verifyOperationToken('internal:wg-easy:state')) {
            return self::FAILURE;
        }

        $action = $this->stringValue($this->option('action'));

        if ($action === null) {
            return $this->invalidOption('action');
        }

        if (! in_array($action, self::Actions, true)) {
            return $this->renderFailure(
                'invalid_action',
                'wg-easy state action must be one of: update-user, update-general, ensure-writable, upsert-peer, delete-peer, update-interface, update-user-password, update-session-password.',
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
            self::ActionUpsertPeer => $this->upsertPeer(),
            self::ActionDeletePeer => $this->deletePeer(),
            self::ActionUpdateInterface => $this->updateInterface(),
            self::ActionUpdateUserPassword => $this->updateUserPassword(),
            self::ActionUpdateSessionPassword => $this->updateSessionPassword(),
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

        return $this->emitInternalSuccess([
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

        return $this->emitInternalSuccess([
            'action' => self::ActionUpdateGeneral,
            'updated' => true,
        ], []);
    }

    private function upsertPeer(): int
    {
        $name = $this->stringValue($this->option('name'));

        if ($name === null) {
            return $this->invalidOption('name');
        }

        $ipv4 = $this->ipv4Value($this->option('ipv4'));

        if ($ipv4 === null) {
            return $this->invalidOption('ipv4');
        }

        $ipv6 = $this->ipv6Value($this->option('ipv6'));

        if ($ipv6 === null) {
            return $this->invalidOption('ipv6');
        }

        $privateKey = $this->stringValue($this->option('private-key'));

        if ($privateKey === null) {
            return $this->invalidOption('private-key');
        }

        $publicKey = $this->stringValue($this->option('public-key'));

        if ($publicKey === null) {
            return $this->invalidOption('public-key');
        }

        $preSharedKey = $this->stringValue($this->option('pre-shared-key'));

        if ($preSharedKey === null) {
            return $this->invalidOption('pre-shared-key');
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            if (! $this->interfaceExists($database)) {
                return $this->interfaceNotFound();
            }

            $database->beginTransaction();

            $this->prepare($database, <<<'SQL'
                delete from clients_table
                where name = :name
                    or public_key = :public_key
                    or ipv4_address = :ipv4_address
                SQL)->execute([
                'name' => $name,
                'public_key' => $publicKey,
                'ipv4_address' => $ipv4,
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
                    :user_id,
                    :interface_id,
                    :name,
                    :ipv4_address,
                    :ipv6_address,
                    :private_key,
                    :public_key,
                    :pre_shared_key,
                    :allowed_ips,
                    :server_allowed_ips,
                    :persistent_keepalive,
                    :mtu,
                    :dns,
                    :enabled
                )
                SQL)->execute([
                'user_id' => 1,
                'interface_id' => self::InterfaceName,
                'name' => $name,
                'ipv4_address' => $ipv4,
                'ipv6_address' => $ipv6,
                'private_key' => $privateKey,
                'public_key' => $publicKey,
                'pre_shared_key' => $preSharedKey,
                'allowed_ips' => '["0.0.0.0/0", "::/0"]',
                'server_allowed_ips' => '["'.$ipv4.'/32"]',
                'persistent_keepalive' => 25,
                'mtu' => 1420,
                'dns' => '["10.6.0.1"]',
                'enabled' => 1,
            ]);

            $database->commit();
        } catch (PDOException) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpsertPeer],
            );
        }

        return $this->emitInternalSuccess([
            'action' => self::ActionUpsertPeer,
            'upserted' => true,
        ], []);
    }

    private function deletePeer(): int
    {
        $identity = $this->peerIdentity();

        if (is_int($identity)) {
            return $identity;
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $statement = $this->prepare(
                $database,
                "delete from clients_table where {$identity['column']} = :identity",
            );
            $statement->execute(['identity' => $identity['value']]);

            if ($statement->rowCount() === 0) {
                return $this->renderFailure(
                    'peer_not_found',
                    'wg-easy peer was not found.',
                    [],
                );
            }
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionDeletePeer],
            );
        }

        return $this->emitInternalSuccess([
            'action' => self::ActionDeletePeer,
            'deleted' => true,
        ], []);
    }

    private function updateInterface(): int
    {
        $interface = $this->interfaceName();

        if ($interface === null) {
            return $this->invalidOption('interface');
        }

        $ipv4Cidr = $this->ipv4CidrValue($this->option('ipv4-cidr'));

        if ($ipv4Cidr === null) {
            return $this->invalidOption('ipv4-cidr');
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            if (! $this->interfaceExists($database)) {
                return $this->interfaceNotFound();
            }

            $this->prepare($database, <<<'SQL'
                update interfaces_table
                set ipv4_cidr = :ipv4_cidr
                where name = :name
                SQL)->execute([
                'ipv4_cidr' => $ipv4Cidr,
                'name' => $interface,
            ]);
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpdateInterface],
            );
        }

        return $this->emitInternalSuccess([
            'action' => self::ActionUpdateInterface,
            'updated' => true,
        ], []);
    }

    private function updateUserPassword(): int
    {
        $passwordHash = $this->passwordHashValue($this->option('password-hash'));

        if ($passwordHash === null) {
            return $this->invalidPasswordHashOption();
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            if (! $this->rowExists($database, 'select 1 from users_table limit 1')) {
                return $this->renderFailure(
                    'user_not_found',
                    'wg-easy user was not found.',
                    [],
                );
            }

            $this->prepare($database, <<<'SQL'
                update users_table
                set password = :password
                SQL)->execute([
                'password' => $passwordHash,
            ]);
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpdateUserPassword],
            );
        }

        return $this->emitInternalSuccess([
            'action' => self::ActionUpdateUserPassword,
            'updated' => true,
        ], []);
    }

    private function updateSessionPassword(): int
    {
        $passwordHash = $this->passwordHashValue($this->option('password-hash'));

        if ($passwordHash === null) {
            return $this->invalidPasswordHashOption();
        }

        $database = $this->openWritableDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            if (! $this->rowExists($database, 'select 1 from general_table limit 1')) {
                return $this->renderFailure(
                    'session_password_not_found',
                    'wg-easy session password row was not found.',
                    [],
                );
            }

            $this->prepare($database, <<<'SQL'
                update general_table
                set session_password = :session_password
                SQL)->execute([
                'session_password' => $passwordHash,
            ]);
        } catch (PDOException) {
            return $this->renderFailure(
                'query_failed',
                'wg-easy database update failed.',
                ['action' => self::ActionUpdateSessionPassword],
            );
        }

        return $this->emitInternalSuccess([
            'action' => self::ActionUpdateSessionPassword,
            'updated' => true,
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
            return $this->emitInternalSuccess([
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

        return $this->emitInternalSuccess([
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

        return @chown($path, posix_getuid()) && @chgrp($path, posix_getgid());
    }

    private function invalidOption(string $field): int
    {
        return $this->renderFailure(
            'validation_failed',
            "The --{$field} option is invalid.",
            ['field' => $field],
        );
    }

    private function invalidPasswordHashOption(): int
    {
        return $this->renderFailure(
            'validation_failed',
            self::InvalidPasswordHashMessage,
            ['field' => 'password-hash'],
        );
    }

    private function interfaceNotFound(): int
    {
        return $this->renderFailure(
            'interface_not_found',
            'wg-easy interface was not found.',
            ['interface' => self::InterfaceName],
        );
    }

    private function interfaceExists(PDO $database): bool
    {
        return $this->rowExists(
            $database,
            'select 1 from interfaces_table where name = :name limit 1',
            ['name' => self::InterfaceName],
        );
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function rowExists(PDO $database, string $sql, array $bindings = []): bool
    {
        $statement = $this->prepare($database, $sql);
        $statement->execute($bindings);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{column: string, value: string}|int
     */
    private function peerIdentity(): array|int
    {
        $provided = [];

        foreach (self::PeerIdentityOptions as $option) {
            $value = $option === 'ipv4'
                ? $this->ipv4Value($this->option($option))
                : $this->stringValue($this->option($option));

            if ($value !== null) {
                $provided[$option] = $value;
            }
        }

        if (count($provided) !== 1) {
            return $this->renderFailure(
                'validation_failed',
                'Exactly one peer identity option is required.',
                ['field' => 'peer-identity', 'allowed' => self::PeerIdentityOptions],
            );
        }

        $option = array_key_first($provided);

        return [
            'column' => match ($option) {
                'name' => 'name',
                'public-key' => 'public_key',
                'ipv4' => 'ipv4_address',
            },
            'value' => $provided[$option],
        ];
    }

    private function interfaceName(): ?string
    {
        $rawInterface = $this->option('interface');

        if ($rawInterface === null) {
            return self::InterfaceName;
        }

        $interface = $this->stringValue($rawInterface);

        if ($interface !== self::InterfaceName) {
            return null;
        }

        return $interface;
    }

    private function ipv4Value(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $value;
    }

    private function ipv6Value(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : $value;
    }

    private function ipv4CidrValue(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_string($prefix)
            || ! ctype_digit($prefix)
        ) {
            return null;
        }

        $prefix = (int) $prefix;

        return $prefix > 32 ? null : $value;
    }

    private function passwordHashValue(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        return $this->isRecognizedPasswordHash($value) ? $value : null;
    }

    private function isRecognizedPasswordHash(string $value): bool
    {
        $info = password_get_info($value);
        $algorithm = $info['algoName'] ?? null;

        return match ($algorithm) {
            'bcrypt' => preg_match(self::BcryptPasswordHashPattern, $value) === 1,
            'argon2i', 'argon2id' => preg_match(self::ArgonPasswordHashPattern, $value) === 1,
            default => false,
        };
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
            || strlen($value) === strlen((string) PHP_INT_MAX)
            && strcmp($value, (string) PHP_INT_MAX) > 0
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
                return $this->stringValue($entry['dir']);
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return rtrim($path, '/');
    }
}
