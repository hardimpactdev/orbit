<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

use Closure;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalCodexAppConfigStore
{
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * @return resource
     */
    public function acquire(string $configPath)
    {
        $this->ensureDirectory($configPath);
        $lockPath = $configPath.'.lock';

        set_error_handler(static fn (): bool => true);

        try {
            $lock = fopen(filename: $lockPath, mode: 'c');
            $permissionsSet = chmod(filename: $lockPath, permissions: 0o600);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($lock)) {
            throw $this->lockFailure($lockPath, 'The lock file could not be opened.');
        }

        if (! $permissionsSet || ! flock($lock, LOCK_EX)) {
            fclose($lock);

            throw $this->lockFailure($lockPath, 'The exclusive lock could not be acquired.');
        }

        return $lock;
    }

    /**
     * @param  resource  $lock
     */
    public function release($lock, string $configPath): void
    {
        $released = flock($lock, LOCK_UN);

        if (! $released) {
            throw $this->lockFailure($configPath.'.lock', 'The exclusive lock could not be released.');
        }

        fclose($lock);
    }

    /**
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment
     */
    public function read(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            $contents = $this->files->get($path);
            $config = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_read_failed',
                message: 'Codex App config is not valid JSON.',
                meta: [
                    'path' => $path,
                    'json_error' => $exception->getMessage(),
                ],
            );
        } catch (Throwable $exception) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_read_failed',
                message: 'Codex App config could not be read.',
                meta: [
                    'path' => $path,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        if (! is_array($config)) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_read_failed',
                message: 'Codex App config must be a JSON object.',
                meta: [
                    'path' => $path,
                    'json_error' => 'The top-level value is not an object.',
                ],
            );
        }

        $normalized = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function replace(string $path, string $contents): void
    {
        $temporary = $this->filesystem(
            static fn (): string|false => tempnam(directory: dirname($path), prefix: 'config.json.tmp.'),
        );

        if (! is_string($temporary)) {
            throw $this->writeFailure($path, 'Codex App config temporary file could not be created.');
        }

        try {
            if (
                $this->filesystem(fn (): int|bool => $this->files->put(path: $temporary, contents: $contents)) === false
            ) {
                throw $this->writeFailure($path, 'Codex App config could not be written.');
            }

            if ($this->filesystem(fn (): mixed => $this->files->chmod(path: $temporary, mode: 0o600)) === false) {
                throw $this->writeFailure($path, 'Codex App config permissions could not be set.');
            }

            if ($this->filesystem(fn (): bool => $this->files->move(path: $temporary, target: $path)) !== true) {
                throw $this->writeFailure($path, 'Codex App config could not be moved into place.');
            }
        } catch (LocalCodexAppConfigFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw $this->writeFailure($path, 'Codex App config replacement failed.', $exception);
        } finally {
            if ($this->files->isFile($temporary)) {
                $this->filesystem(fn (): bool => $this->files->delete(paths: $temporary));
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        try {
            if (
                ! $this->files->isDirectory($directory)
                && ! $this->files->makeDirectory(path: $directory, mode: 0o700, recursive: true)
                && ! $this->files->isDirectory($directory)
            ) {
                throw $this->writeFailure($path, 'Codex App config directory could not be created.');
            }

            if ($this->filesystem(fn (): mixed => $this->files->chmod(path: $directory, mode: 0o700)) === false) {
                throw $this->writeFailure($path, 'Codex App config directory permissions could not be set.');
            }
        } catch (LocalCodexAppConfigFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw $this->writeFailure($path, 'Codex App config directory could not be prepared.', $exception);
        }
    }

    private function lockFailure(string $path, string $reason): LocalCodexAppConfigFailure
    {
        return new LocalCodexAppConfigFailure(
            errorCode: 'codex_app.config_lock_failed',
            message: 'Codex App config lock failed.',
            meta: [
                'path' => $path,
                'reason' => $reason,
            ],
        );
    }

    private function writeFailure(
        string $path,
        string $message,
        ?Throwable $exception = null,
    ): LocalCodexAppConfigFailure {
        $meta = ['path' => $path];

        if ($exception instanceof Throwable) {
            $meta['reason'] = $exception->getMessage();
        }

        return new LocalCodexAppConfigFailure(
            errorCode: 'codex_app.config_write_failed',
            message: $message,
            meta: $meta,
        );
    }

    /**
     * @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function filesystem(Closure $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
