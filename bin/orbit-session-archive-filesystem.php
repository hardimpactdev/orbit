<?php

declare(strict_types=1);

function orbitSessionArchiveEnsureDirectory(string $directory): void
{
    $stat = @lstat($directory);

    if ($stat !== false) {
        if (! orbitSessionArchiveStatIsDirectory($stat)) {
            throw new RuntimeException("Archive directory must be a real directory, not a symlink: {$directory}");
        }

        return;
    }

    if (! @mkdir($directory, 0o775, true)) {
        throw new RuntimeException("Unable to create directory: {$directory}");
    }

    $created = @lstat($directory);

    if ($created === false || ! orbitSessionArchiveStatIsDirectory($created)) {
        throw new RuntimeException("Archive directory was replaced during creation: {$directory}");
    }
}

function orbitSessionArchiveWriteFile(string $path, string $contents): void
{
    orbitSessionArchiveEnsureDirectory(dirname($path));
    $written = @file_put_contents($path, $contents);

    if ($written !== strlen($contents)) {
        throw new RuntimeException("Unable to write file: {$path}");
    }
}

function orbitSessionArchiveWriteFileAtomically(string $path, string $contents): void
{
    $temporaryPath = dirname($path).'/.'.basename($path).'.tmp-'.bin2hex(random_bytes(8));

    try {
        orbitSessionArchiveWriteFile($temporaryPath, $contents);

        if (! @rename($temporaryPath, $path)) {
            throw new RuntimeException("Unable to atomically replace file: {$path}");
        }
    } catch (Throwable $exception) {
        if (file_exists($temporaryPath) || is_link($temporaryPath)) {
            orbitSessionArchiveRemovePath($temporaryPath);
        }

        throw $exception;
    }
}

function orbitSessionArchiveCopyFile(string $source, string $target, ?callable $afterSourceInspection = null): void
{
    $initial = @lstat($source);

    if ($initial === false || ! orbitSessionArchiveStatIsRegular($initial)) {
        throw new RuntimeException("Archive source must be a regular file, not a symlink: {$source}");
    }

    if ($afterSourceInspection !== null) {
        $afterSourceInspection();
    }

    $sourceHandle = @fopen($source, 'rb');

    if ($sourceHandle === false) {
        throw new RuntimeException("Archive source changed before copy: {$source}");
    }

    orbitSessionArchiveEnsureDirectory(dirname($target));
    $temporaryTarget = dirname($target).'/.'.basename($target).'.copy-'.bin2hex(random_bytes(8));
    $targetHandle = null;

    try {
        $opened = fstat($sourceHandle);
        $current = @lstat($source);

        if (
            $opened === false
            || $current === false
            || ! orbitSessionArchiveSameFileSnapshot($initial, $opened)
            || ! orbitSessionArchiveSameFileSnapshot($initial, $current)
        ) {
            throw new RuntimeException("Archive source changed before copy: {$source}");
        }

        $targetHandle = @fopen($temporaryTarget, 'x+b');

        if ($targetHandle === false) {
            throw new RuntimeException("Unable to create archive copy target: {$temporaryTarget}");
        }

        $copied = stream_copy_to_stream($sourceHandle, $targetHandle);

        if ($copied === false || $copied !== (int) $initial['size'] || ! fflush($targetHandle)) {
            throw new RuntimeException("Unable to copy file: {$source} -> {$target}");
        }

        if (function_exists('fsync') && ! fsync($targetHandle)) {
            throw new RuntimeException("Unable to sync copied archive file: {$target}");
        }

        $afterOpened = fstat($sourceHandle);
        $afterCurrent = @lstat($source);

        if (
            $afterOpened === false
            || $afterCurrent === false
            || ! orbitSessionArchiveSameFileSnapshot($initial, $afterOpened)
            || ! orbitSessionArchiveSameFileSnapshot($initial, $afterCurrent)
        ) {
            throw new RuntimeException("Archive source changed during copy: {$source}");
        }

        fclose($targetHandle);
        $targetHandle = null;

        if (! @rename($temporaryTarget, $target)) {
            throw new RuntimeException("Unable to activate copied archive file: {$target}");
        }
    } finally {
        fclose($sourceHandle);

        if (is_resource($targetHandle)) {
            fclose($targetHandle);
        }

        if (file_exists($temporaryTarget) || is_link($temporaryTarget)) {
            @unlink($temporaryTarget);
        }
    }
}

/** @param array<int|string, mixed> $stat */
function orbitSessionArchiveStatIsDirectory(array $stat): bool
{
    return ((int) $stat['mode'] & 0o170000) === 0o040000;
}

/** @param array<int|string, mixed> $stat */
function orbitSessionArchiveStatIsRegular(array $stat): bool
{
    return ((int) $stat['mode'] & 0o170000) === 0o100000;
}

/**
 * @param array<int|string, mixed> $left
 * @param array<int|string, mixed> $right
 */
function orbitSessionArchiveSameFileSnapshot(array $left, array $right): bool
{
    return (
        (int) $left['dev'] === (int) $right['dev']
        && (int) $left['ino'] === (int) $right['ino']
        && (int) $left['size'] === (int) $right['size']
        && (int) $left['mtime'] === (int) $right['mtime']
        && ((int) $left['mode'] & 0o170000) === ((int) $right['mode'] & 0o170000)
    );
}

function orbitSessionArchiveRemovePath(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (is_dir($path) && ! is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            orbitSessionArchiveRemovePath($entry->getPathname());
        }

        if (! @rmdir($path)) {
            throw new RuntimeException("Unable to remove directory: {$path}");
        }

        return;
    }

    if (! @unlink($path)) {
        throw new RuntimeException("Unable to remove file: {$path}");
    }
}

function orbitSessionArchivePathIdentity(string $path): string
{
    $stat = @lstat($path);

    if ($stat === false) {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("Unable to inspect archive path identity: {$path}");
        }

        return 'absent';
    }

    return "present:{$stat['dev']}:{$stat['ino']}:{$stat['mode']}";
}

/**
 * @param null|callable(string, string): bool $rename
 */
function swapArchiveDirectories(
    string $temporaryArchiveDir,
    string $archiveDir,
    string $backupDir,
    ?callable $rename = null,
    ?string $expectedArchiveIdentity = null,
): void {
    $rename ??= static fn (string $source, string $destination): bool => @rename($source, $destination);
    $archiveIdentity = orbitSessionArchivePathIdentity($archiveDir);

    if ($expectedArchiveIdentity !== null && $archiveIdentity !== $expectedArchiveIdentity) {
        orbitSessionArchiveRemovePath($temporaryArchiveDir);

        throw new RuntimeException(
            "Unexpected archive final appeared or changed before swap: {$archiveDir}; "
            ."expected={$expectedArchiveIdentity}; actual={$archiveIdentity}",
        );
    }

    $hadFinal = $archiveIdentity !== 'absent';

    if ($hadFinal && ! $rename($archiveDir, $backupDir)) {
        orbitSessionArchiveRemovePath($temporaryArchiveDir);

        throw new RuntimeException(
            "Unable to move the previous archive into backup before swap: {$archiveDir} -> {$backupDir}",
        );
    }

    if ($rename($temporaryArchiveDir, $archiveDir)) {
        return;
    }

    if (! $hadFinal) {
        throw new RuntimeException(
            "Unable to activate completed archive; complete temp retained at {$temporaryArchiveDir}; "
            ."no previous final or backup existed; final remains absent at {$archiveDir}",
        );
    }

    $rollbackSucceeded = $rename($backupDir, $archiveDir);

    if (! $rollbackSucceeded) {
        throw new RuntimeException(
            "Unable to activate completed archive and rollback failed; retained complete temp={$temporaryArchiveDir}; "
            ."retained previous backup={$backupDir}; final={$archiveDir}",
        );
    }

    orbitSessionArchiveRemovePath($temporaryArchiveDir);

    throw new RuntimeException(
        "Unable to activate completed archive; previous final rolled back: temp={$temporaryArchiveDir}; "
        ."final={$archiveDir}; backup={$backupDir}",
    );
}
