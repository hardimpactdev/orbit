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

    /**
     * @var list<string>
     */
    private const Actions = [
        self::ActionUpdateUser,
        self::ActionUpdateGeneral,
        self::ActionEnsureWritable,
    ];

    protected $signature = 'internal:wg-easy:state
        {--action=}
        {--operation-token=}
        {--json}
        {--host=}
        {--default-dns=}
        {--default-persistent-keepalive=}
        {--setup-step=}';

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
                'wg-easy state action must be one of: update-user, update-general, ensure-writable.',
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

    private function ensureWritable(): int
    {
        $path = $this->databasePath();

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

    private function databasePath(): ?string
    {
        $override = $this->environmentPath('ORBIT_WG_EASY_DB_PATH');

        if ($override !== null) {
            return $override;
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
        $value = getenv($key);

        return $this->stringValue($value);
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
}
