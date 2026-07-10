<?php

declare(strict_types=1);

function orbitAgentSessionCaptureAssertDirectChildPath(string $path, string $canonicalProviderRoot): void
{
    $normalizedRoot = rtrim($canonicalProviderRoot, '/');

    if ($normalizedRoot === '') {
        $normalizedRoot = '/';
    }

    if (
        is_link($normalizedRoot)
        || ! is_dir($normalizedRoot)
        || ($realProviderRoot = realpath($normalizedRoot)) === false
        || $realProviderRoot !== $normalizedRoot
    ) {
        throw new RuntimeException("provider_root_not_canonical provider_root={$normalizedRoot}");
    }

    $parent = dirname($path);
    $realParent = realpath($parent);

    if (
        $parent !== $normalizedRoot
        || $realParent !== $normalizedRoot
        || in_array(basename($path), ['', '.', '..'], true)
    ) {
        throw new RuntimeException("not_direct_child path={$path} provider_root={$normalizedRoot}");
    }
}

function orbitAgentSessionCaptureEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
        throw new RuntimeException("create_directory_failed path={$directory}");
    }
}

/** @return resource|false */
function orbitAgentSessionCaptureOpenPinnedRegularFile(string $path): mixed
{
    $pathStat = @lstat($path);

    if ($pathStat === false || ($pathStat['mode'] & 0170000) !== 0100000) {
        return false;
    }

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return false;
    }

    $handleStat = @fstat($handle);

    if (
        $handleStat === false
        || ($handleStat['mode'] & 0170000) !== 0100000
        || $handleStat['dev'] !== $pathStat['dev']
        || $handleStat['ino'] !== $pathStat['ino']
    ) {
        fclose($handle);

        return false;
    }

    return $handle;
}

function orbitAgentSessionCaptureReadPinnedRegularFile(string $path): ?string
{
    $handle = orbitAgentSessionCaptureOpenPinnedRegularFile($path);

    if ($handle === false) {
        return null;
    }

    try {
        $contents = stream_get_contents($handle);

        return $contents === false ? null : $contents;
    } finally {
        fclose($handle);
    }
}

function orbitAgentSessionCaptureRemovePathRecursively(string $path, string $canonicalProviderRoot): void
{
    orbitAgentSessionCaptureAssertDirectChildPath($path, $canonicalProviderRoot);

    if (is_link($path) || is_file($path)) {
        if (! unlink($path)) {
            throw new RuntimeException("remove_path_failed path={$path}");
        }

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            if (! unlink($entry->getPathname())) {
                throw new RuntimeException("remove_path_failed path={$entry->getPathname()}");
            }

            continue;
        }

        if (! rmdir($entry->getPathname())) {
            throw new RuntimeException("remove_directory_failed path={$entry->getPathname()}");
        }
    }

    if (! rmdir($path)) {
        throw new RuntimeException("remove_directory_failed path={$path}");
    }
}

/**
 * @param array<string, mixed> $manifest
 * @param null|array<string, mixed> $usage
 * @param list<array{path: string, archive_name: string}> $rawFiles
 */
function orbitAgentSessionCaptureBuildStagingDirectory(
    string $canonicalProviderRoot,
    string $temporaryStaging,
    array $manifest,
    ?array $usage,
    ?string $messagesJsonl,
    array $rawFiles,
    ?callable $write = null,
    ?callable $copy = null,
): void {
    orbitAgentSessionCaptureAssertDirectChildPath($temporaryStaging, $canonicalProviderRoot);

    $write ??= static fn (string $path, string $contents): int|false => @file_put_contents($path, $contents);
    $copy ??= static function (mixed $sourceHandle, string $destination): bool {
        $destinationHandle = @fopen($destination, 'wb');

        if ($destinationHandle === false) {
            return false;
        }

        try {
            return stream_copy_to_stream($sourceHandle, $destinationHandle) !== false;
        } finally {
            fclose($destinationHandle);
        }
    };

    try {
        if (is_link($temporaryStaging)) {
            throw new RuntimeException("symlinked_temporary_staging path={$temporaryStaging}");
        }

        orbitAgentSessionCaptureEnsureDirectory($temporaryStaging);
        orbitAgentSessionCaptureAssertDirectChildPath($temporaryStaging, $canonicalProviderRoot);

        if (is_link($temporaryStaging)) {
            throw new RuntimeException("symlinked_temporary_staging path={$temporaryStaging}");
        }

        $manifestPath = "{$temporaryStaging}/manifest.json";
        $manifestContents = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        if ($write($manifestPath, $manifestContents) === false) {
            throw new RuntimeException("write_failed operation=manifest path={$manifestPath}");
        }

        if ($usage !== null) {
            $usagePath = "{$temporaryStaging}/usage.json";
            $usageContents = json_encode(
                $usage,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            if ($write($usagePath, $usageContents) === false) {
                throw new RuntimeException("write_failed operation=usage path={$usagePath}");
            }
        }

        if ($messagesJsonl !== null) {
            $messagesPath = "{$temporaryStaging}/messages.jsonl";

            if ($write($messagesPath, $messagesJsonl) === false) {
                throw new RuntimeException("write_failed operation=messages path={$messagesPath}");
            }
        }

        if ($rawFiles === []) {
            return;
        }

        $rawDirectory = "{$temporaryStaging}/raw";
        orbitAgentSessionCaptureEnsureDirectory($rawDirectory);

        foreach ($rawFiles as $rawFile) {
            $source = $rawFile['path'];
            $archiveName = $rawFile['archive_name'];

            if (is_link($source)) {
                throw new RuntimeException("raw_source_symlink source={$source}");
            }

            if (
                $archiveName === ''
                || in_array($archiveName, ['.', '..'], true)
                || basename($archiveName) !== $archiveName
            ) {
                throw new RuntimeException("invalid_raw_archive_name archive_name={$archiveName}");
            }

            $destination = "{$rawDirectory}/{$archiveName}";
            $sourceHandle = orbitAgentSessionCaptureOpenPinnedRegularFile($source);

            if ($sourceHandle === false) {
                throw new RuntimeException("raw_source_missing source={$source}");
            }

            try {
                if (! $copy($sourceHandle, $destination)) {
                    throw new RuntimeException("copy_failed operation=raw source={$source} path={$destination}");
                }
            } finally {
                fclose($sourceHandle);
            }
        }
    } catch (Throwable $throwable) {
        if (is_dir($temporaryStaging) || is_link($temporaryStaging) || is_file($temporaryStaging)) {
            orbitAgentSessionCaptureRemovePathRecursively($temporaryStaging, $canonicalProviderRoot);
        }

        throw $throwable;
    }
}

function orbitAgentSessionCaptureReplaceStagedDirectory(
    string $canonicalProviderRoot,
    string $temp,
    string $final,
    string $backup,
    ?callable $rename = null,
): void {
    orbitAgentSessionCaptureAssertDirectChildPath($temp, $canonicalProviderRoot);
    orbitAgentSessionCaptureAssertDirectChildPath($final, $canonicalProviderRoot);
    orbitAgentSessionCaptureAssertDirectChildPath($backup, $canonicalProviderRoot);

    $rename ??= static fn (string $from, string $to): bool => @rename($from, $to);
    $hadFinal = file_exists($final) || is_link($final);

    if ($hadFinal && ! $rename($final, $backup)) {
        throw new RuntimeException("final_to_backup_failed temp={$temp} final={$final} backup={$backup}");
    }

    if (! $rename($temp, $final)) {
        if (! $hadFinal) {
            throw new RuntimeException("temp_to_final_failed_no_backup temp={$temp} final={$final} backup={$backup}");
        }

        if (! $rename($backup, $final)) {
            throw new RuntimeException("temp_to_final_and_rollback_failed temp={$temp} final={$final} backup={$backup}");
        }

        orbitAgentSessionCaptureRemovePathRecursively($temp, $canonicalProviderRoot);

        throw new RuntimeException("temp_to_final_failed_rolled_back temp={$temp} final={$final} backup={$backup}");
    }

    if ($hadFinal) {
        orbitAgentSessionCaptureRemovePathRecursively($backup, $canonicalProviderRoot);
    }
}
