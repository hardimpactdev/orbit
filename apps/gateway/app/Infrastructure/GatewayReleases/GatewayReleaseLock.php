<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use Closure;

/**
 * The single-flight lock every release step runs under. It is a `flock` on a file in
 * `ORBIT_HOME`, held for the life of the process, so a crashed release frees it at once and no
 * time-to-live has to outlast a slow `composer install`. The file is opened close-on-exec, so a
 * child that outlives the release, such as a detached `git gc`, never holds the lock.
 */
final readonly class GatewayReleaseLock
{
    public function __construct(private string $path, private GatewayReleaseCommands $commands) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->unavailable();
        }

        $this->existing($this->path);
        $this->existing($this->stepPath());
        $handle = $this->openPrivate($this->path);

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new GatewayReleaseException(
                    step: 'lock',
                    errorCode: 'gateway.release_in_progress',
                    message: 'Another Gateway release step is running. Wait for it to finish.',
                );
            }

            $this->assertNoStepRunning();

            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** The lock that a release step's commands hold while they run, next to this lock. */
    public function stepPath(): string
    {
        return dirname($this->path).'/gateway-release-step.lock';
    }

    /**
     * Refuses while a command from an earlier release step still runs, for example a migration that went on after
     * the process that started it died.
     */
    private function assertNoStepRunning(): void
    {
        $step = $this->openPrivate($this->stepPath());
        fclose($step);
        if ($this->commands->stepBusy($this->stepPath())) {
            throw new GatewayReleaseException(
                step: 'lock',
                errorCode: 'gateway.release_in_progress',
                message: 'A command from an earlier release step still runs, such as a migration whose release process died. Wait for it to finish.',
            );
        }
    }

    /** @return resource */
    private function openPrivate(string $path)
    {
        $this->existing($path);
        // Exclusive creation never follows or changes an existing inode. Restore the caller's mask immediately.
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'xe');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            $this->existing($path);
            $handle = @fopen($path, 'r+e');
        }
        if ($handle === false) {
            throw $this->unavailable();
        }
        $opened = fstat($handle);
        try {
            $named = $this->existing($path);
            if ($opened === false || $named === null || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
                throw $this->unavailable();
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        return $handle;
    }

    /** @return array<string|int, int>|null */
    private function existing(string $path): ?array
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if ($info === false) {
            return null;
        }
        if (($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 07777) !== 0600 || $info['uid'] !== posix_geteuid()) {
            throw $this->unavailable();
        }

        return $info;
    }

    private function unavailable(): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'lock',
            errorCode: 'gateway.release_lock_unavailable',
            message: "The Gateway release lock [{$this->path}] cannot be opened.",
            status: 500,
        );
    }
}
