<?php

declare(strict_types=1);

function orbitSessionArchiveEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory: {$directory}");
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

function orbitSessionArchiveCopyFile(string $source, string $target): void
{
    orbitSessionArchiveEnsureDirectory(dirname($target));

    if (! @copy($source, $target)) {
        throw new RuntimeException("Unable to copy file: {$source} -> {$target}");
    }
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
