<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * What a task image carries from a release: the playbooks of the task VM role, the pins of the recipe and the lock files of
 * the repository. A ready slot made from an older digest is stale (stage 6, docs/reference/pipeline/6-task-image-seed-refresh.mmd).
 */
final readonly class TaskVmImageInputs
{
    /** Folders whose every file counts, relative to the root of a release. */
    private const array Folders = ['swamp/ansible', 'apps/gateway/resources/machine-software'];

    /** Lock files, as glob patterns relative to the root of a release. */
    private const array Locks = ['composer.lock', 'apps/*/composer.lock', 'apps/*/bun.lock', 'apps/*/bun.lockb', 'apps/*/package-lock.json', 'apps/*/Cargo.lock', 'Cargo.lock'];

    /** One digest over the path and the content of every file the image carries from the release at `$root`. */
    public function digest(string $root): string
    {
        $files = [];
        foreach (self::Folders as $folder) {
            if (! is_dir($root.'/'.$folder)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && ! str_contains($file->getPathname(), '/__pycache__/')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach (self::Locks as $pattern) {
            array_push($files, ...array_filter(glob($root.'/'.$pattern) ?: [], is_file(...)));
        }
        $relative = array_map(static fn (string $path): string => substr($path, strlen($root) + 1), $files);
        sort($relative);
        $context = hash_init('sha256');
        foreach (array_unique($relative) as $path) {
            hash_update($context, $path."\0".hash_file('sha256', $root.'/'.$path)."\n");
        }

        return hash_final($context);
    }

    /** Whether the release at `$current` differs from the one at `$previous` in what the image carries. A release that is gone counts as changed. */
    public function changed(string $previous, string $current): bool
    {
        return ! is_dir($previous) || ! is_dir($current) || $this->digest($previous) !== $this->digest($current);
    }
}
