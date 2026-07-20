<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

final readonly class LocalEnvFileAction
{
    private const array Actions = ['read', 'write'];

    private const array AllowedRootPrefixes = [
        '/home/orbit/',
        '/srv/',
        '/var/www/',
    ];

    private const string PRODUCTION_APP_ENV_PATTERN = '#\A/home/[a-z_][a-z0-9_-]*/app/\.env\z#';

    private const string DEVELOPMENT_APP_ENV_PATTERN = '#\A(?:/home/[a-z_][a-z0-9_-]*|/Users/[A-Za-z0-9][A-Za-z0-9._-]*)/apps/[a-z0-9][a-z0-9._-]*/\.env\z#';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $action = $this->action($payload['action'] ?? null);
        $path = $this->path($payload['path'] ?? null);

        if ($action === 'read') {
            return $this->read($path);
        }

        return $this->write($path, $this->contents($payload['contents'] ?? null));
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new LocalEnvFileFailure(
                errorCode: 'env_file.not_found',
                message: 'Env file was not found.',
                meta: ['path' => $path],
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new LocalEnvFileFailure(
                errorCode: 'env_file.read_failed',
                message: 'Env file could not be read.',
                meta: ['path' => $path],
            );
        }

        return [
            'data' => [
                'path' => $path,
                'contents' => $contents,
            ],
            'meta' => [],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function write(string $path, string $contents): array
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new LocalEnvFileFailure(
                errorCode: 'env_file.directory_failed',
                message: 'Env file directory could not be created.',
                meta: ['path' => $path],
            );
        }

        if (file_put_contents($path, $contents) === false) {
            throw new LocalEnvFileFailure(
                errorCode: 'env_file.write_failed',
                message: 'Env file could not be written.',
                meta: ['path' => $path],
            );
        }

        return [
            'data' => [
                'path' => $path,
                'bytes' => strlen($contents),
            ],
            'meta' => [],
        ];
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::Actions, true)) {
            return $value;
        }

        throw new LocalEnvFileFailure(
            errorCode: 'validation_failed',
            message: 'Env file action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function path(mixed $value): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw $this->invalidPath();
        }

        if (! str_starts_with($value, '/') || ! str_ends_with($value, '/.env')) {
            throw $this->invalidPath();
        }

        if (
            preg_match(self::PRODUCTION_APP_ENV_PATTERN, $value) === 1
            || preg_match(self::DEVELOPMENT_APP_ENV_PATTERN, $value) === 1
        ) {
            return $value;
        }

        foreach (self::AllowedRootPrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        throw $this->invalidPath();
    }

    private function contents(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new LocalEnvFileFailure(
            errorCode: 'validation_failed',
            message: 'Env file contents must be a string.',
            meta: ['field' => 'contents'],
        );
    }

    private function invalidPath(): LocalEnvFileFailure
    {
        return new LocalEnvFileFailure(
            errorCode: 'validation_failed',
            message: 'Env file path is invalid.',
            meta: ['field' => 'path'],
        );
    }
}
