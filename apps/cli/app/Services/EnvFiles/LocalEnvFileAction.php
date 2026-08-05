<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

final readonly class LocalEnvFileAction
{
    private const array Actions = ['read', 'write'];

    public function __construct(
        private RuntimeUserEnvFileWriter $runtimeUserWriter,
        private LocalEnvFilePath $paths = new LocalEnvFilePath,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $action = $this->action($payload['action'] ?? null);
        $path = $this->paths->validate($payload['path'] ?? null);

        if ($action === 'read') {
            return $this->read($path);
        }

        $contents = $this->contents($payload['contents'] ?? null);
        // Validate before either write path so a directory/symlink .env cannot
        // reach stage/rename or the runtime-user publish script.
        $this->paths->assertWritableTarget($path);
        $runtimeUser = $payload['runtime_user'] ?? null;

        if ($runtimeUser !== null) {
            return $this->runtimeUserWriter->write($path, $contents, $runtimeUser);
        }

        return $this->write($path, $contents);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(string $path): array
    {
        if (! $this->paths->isReadableFile($path)) {
            throw new LocalEnvFileFailure(errorCode: 'env_file.not_found', message: 'Env file was not found.', meta: [
                'path' => $path,
            ]);
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

        // Re-check after mkdir: parent must still be a non-escaping real directory.
        $this->paths->assertWritableTarget($path);

        $mode = is_file($path) ? fileperms($path) & 0o777 : 0o600;
        $temporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new LocalEnvFileFailure(
                    errorCode: 'env_file.write_failed',
                    message: 'Env file could not be written.',
                    meta: ['path' => $path],
                );
            }

            if (! chmod($temporary, $mode)) {
                throw new LocalEnvFileFailure(
                    errorCode: 'env_file.write_failed',
                    message: 'Env file permissions could not be set.',
                    meta: ['path' => $path],
                );
            }

            // Revalidate immediately before same-directory rename.
            $this->paths->assertWritableTarget($path);

            if (! rename($temporary, $path)) {
                throw new LocalEnvFileFailure(
                    errorCode: 'env_file.write_failed',
                    message: 'Env file could not be published.',
                    meta: ['path' => $path],
                );
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
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

        throw new LocalEnvFileFailure(errorCode: 'validation_failed', message: 'Env file action is invalid.', meta: [
            'field' => 'action',
        ]);
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
}
