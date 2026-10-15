<?php

declare(strict_types=1);

/*
 * Returns the cache directory of one Rector process in a project.
 *
 * Rector writes a cache entry with copy() and reads it with require. Every process rewrites the same configuration
 * entry when it starts, so parallel workers that share a directory can read a half-written file and fail with a
 * syntax error. The main process keeps the project's directory, which `composer rector` clears with --clear-cache.
 * Each worker uses its own directory under it, in workers/, and removes it when it exits. A worker that is killed
 * leaves its directory there, and the next run's --clear-cache removes it with the rest of the cache.
 */

return static function (string $project): string {
    $arguments = $_SERVER['argv'] ?? [];
    $command = null;
    if (is_array($arguments)) {
        foreach (array_slice($arguments, 1) as $argument) {
            if (is_string($argument) && ! str_starts_with($argument, '-')) {
                $command = $argument;

                break;
            }
        }
    }

    if ($command !== 'worker') {
        return $project.'/vendor/rector/cache';
    }

    $directory = $project.'/vendor/rector/cache/workers/'.getmypid().'-'.bin2hex(random_bytes(4));
    register_shutdown_function(static function () use ($directory): void {
        if (! is_dir($directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    });

    return $directory;
};
