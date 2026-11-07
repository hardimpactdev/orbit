<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * What a task image carries from a release: the playbooks of the task VM role, the pins of the recipe and the lock files of
 * the repository. The proof asks whether the ready copies of a host include every change of these files
 * (stage 6, docs/reference/pipeline/6-task-image-seed-refresh.mmd).
 */
final readonly class TaskVmImageInputs
{
    /** Folders whose every file counts, relative to the root of a release. */
    private const array Folders = ['swamp/ansible', 'apps/gateway/resources/machine-software'];

    /** Lock files, as glob patterns relative to the root of a release. */
    private const array Locks = ['composer.lock', 'apps/*/composer.lock', 'apps/*/bun.lock', 'apps/*/bun.lockb', 'apps/*/package-lock.json', 'apps/*/Cargo.lock', 'Cargo.lock'];

    /**
     * Whether the file at `$path`, relative to the root of a release, is one of the files the image carries, which the proof asks the ready copies for.
     */
    public function carries(string $path): bool
    {
        foreach (self::Folders as $folder) {
            // Compiled files of the folders do not count.
            if (str_starts_with($path, $folder.'/') && ! str_contains('/'.$path, '/__pycache__/')) {
                return true;
            }
        }
        foreach (self::Locks as $pattern) {
            if (fnmatch($pattern, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}
