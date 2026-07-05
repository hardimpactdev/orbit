<?php

declare(strict_types=1);

namespace App\Services\Database;

use Symfony\Component\Process\Process;

final readonly class LocalMysqlUserAction
{
    private const string IdentifierPattern = '/^[A-Za-z0-9_]{1,64}$/';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $container = $this->container($payload['container'] ?? null);
        $database = $this->identifier('database', $payload['database'] ?? null);
        $username = $this->identifier('username', $payload['username'] ?? null);
        $password = $this->password($payload['password'] ?? null);

        $this->ensureContainerExists($container);
        $this->waitUntilReady($container);
        $this->applySql($container, $this->sql($database, $username, $password));

        return [
            'data' => [
                'container' => $container,
                'database' => $database,
                'username' => $username,
            ],
            'meta' => [],
        ];
    }

    private function container(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'validation_failed',
            message: 'Managed MySQL container name is invalid.',
            meta: ['field' => 'container'],
        );
    }

    private function identifier(string $field, mixed $value): string
    {
        if (is_string($value) && preg_match(self::IdentifierPattern, $value) === 1) {
            return $value;
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'validation_failed',
            message: "MySQL {$field} must use only letters, digits, and underscores, and be at most 64 characters.",
            meta: ['field' => $field],
        );
    }

    private function password(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'validation_failed',
            message: 'MySQL password is required.',
            meta: ['field' => 'password'],
        );
    }

    private function ensureContainerExists(string $container): void
    {
        $result = $this->runProcess(['docker', 'inspect', $container]);

        if ($result->isSuccessful()) {
            return;
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'database_user.container_not_found',
            message: "Managed MySQL container '{$container}' was not found.",
            meta: ['container' => $container],
        );
    }

    private function waitUntilReady(string $container): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $result = $this->runProcess([
                'docker',
                'exec',
                $container,
                'sh',
                '-lc',
                'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot --silent',
            ]);

            if ($result->isSuccessful()) {
                return;
            }

            sleep(2);
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'database_user.container_not_ready',
            message: "Managed MySQL container '{$container}' did not become ready.",
            meta: ['container' => $container],
        );
    }

    private function applySql(string $container, string $sql): void
    {
        $result = $this->runProcess(
            [
                'docker',
                'exec',
                '-i',
                $container,
                'sh',
                '-lc',
                'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot',
            ],
            $sql,
        );

        if ($result->isSuccessful()) {
            return;
        }

        throw new LocalMysqlUserFailure(
            errorCode: 'database_user.apply_failed',
            message: 'Could not add MySQL user on managed process.',
            meta: [
                'container' => $container,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, ?string $input = null): Process
    {
        $process = new Process($command);
        $process->setTimeout(120);

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        return $process;
    }

    private function sql(string $database, string $username, string $password): string
    {
        return implode(PHP_EOL, [
            'CREATE DATABASE IF NOT EXISTS '
                .$this->quotedIdentifier($database)
                .' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            'CREATE USER IF NOT EXISTS '
                .$this->stringLiteral($username)
                ."@'%' IDENTIFIED BY "
                .$this->stringLiteral($password)
                .';',
            'ALTER USER '.$this->stringLiteral($username)."@'%' IDENTIFIED BY ".$this->stringLiteral($password).';',
            'GRANT ALL PRIVILEGES ON '
                .$this->quotedIdentifier($database)
                .'.* TO '
                .$this->stringLiteral($username)
                ."@'%';",
            'FLUSH PRIVILEGES;',
            '',
        ]);
    }

    private function quotedIdentifier(string $value): string
    {
        return "`{$value}`";
    }

    private function stringLiteral(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
