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
    /** @param string $cgroups the control-group file system, where a step command's service shows whether it still runs */
    public function __construct(private string $path, private string $cgroups = '/sys/fs/cgroup') {}

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
     * the process that started it died. A step command writes its service's control group into the step lock, so a
     * released lock whose service still has processes counts as running too.
     */
    private function assertNoStepRunning(): void
    {
        $step = $this->openPrivate($this->stepPath());

        try {
            if (! flock($step, LOCK_EX | LOCK_NB)) {
                throw $this->stepRunning();
            }

            try {
                $group = stream_get_contents($step, 4098, 0);
                if ($group === false) {
                    throw $this->unavailable();
                }
                if ($group !== '' && $this->populated($group)) {
                    throw $this->stepRunning();
                }
            } finally {
                flock($step, LOCK_UN);
            }
        } finally {
            fclose($step);
        }
    }

    /** Whether the recorded `orbit-command-*.service` control group still has processes. A removed group has none. */
    private function populated(string $record): bool
    {
        $group = substr($record, 0, -1);
        if (! str_ends_with($record, "\n") || strlen($group) > 4096 || preg_match('/[[:cntrl:]]/', $group) === 1
            || preg_match('#\A(/[^/]+)+\z#', $group) !== 1 || array_intersect(explode('/', $group), ['.', '..']) !== []
            || preg_match('/\Aorbit-command-.+\.service\z/', basename($group)) !== 1) {
            throw $this->unavailable();
        }
        $events = @file_get_contents($this->cgroups.$group.'/cgroup.events');
        if ($events === false) {
            return false;
        }

        return match (preg_match('/^populated ([01])$/m', $events, $match) === 1 ? $match[1] : null) {
            '0' => false,
            '1' => true,
            default => throw $this->unavailable(),
        };
    }

    private function stepRunning(): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'lock',
            errorCode: 'gateway.release_in_progress',
            message: 'A command from an earlier release step still runs, such as a migration whose release process died. Wait for it to finish.',
        );
    }

    /** @return resource */
    private function openPrivate(string $path)
    {
        $this->existing($path);
        // Exclusive creation never follows or changes an existing inode. Restore the caller's mask immediately.
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x+e');
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
