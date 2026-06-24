<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class E2EArtifactBuildFingerprint
{
    /**
     * @return non-empty-string
     */
    public static function cliBinary(): string
    {
        return self::forPaths([
            'apps/cli',
            'packages/core',
            'packages/sdk',
        ], [
            'apps/cli/builds',
            'apps/cli/vendor',
            'packages/core/vendor',
            'packages/sdk/vendor',
        ]);
    }

    /**
     * @return non-empty-string
     */
    public static function gatewayImage(): string
    {
        return self::forPaths([
            'apps/gateway/artisan',
            'apps/gateway/composer.json',
            'apps/gateway/composer.lock',
            'apps/gateway/.env.example',
            'apps/gateway/app',
            'apps/gateway/bootstrap',
            'apps/gateway/config',
            'apps/gateway/database',
            'apps/gateway/public',
            'apps/gateway/resources/css',
            'apps/gateway/resources/js',
            'apps/gateway/resources/node-scripts',
            'apps/gateway/resources/views',
            'apps/gateway/routes',
            'packages/core/composer.json',
            'packages/core/composer.lock',
            'packages/core/src',
            'packages/sdk/composer.json',
            'packages/sdk/composer.lock',
            'packages/sdk/src',
            'docker/orbit-gateway',
        ], [
            'apps/gateway/bootstrap/cache',
            'apps/gateway/database/database.sqlite',
            'apps/gateway/database/database.sqlite-shm',
            'apps/gateway/database/database.sqlite-wal',
            'apps/gateway/storage/framework',
            'apps/gateway/storage/logs',
            'packages/sdk/vendor',
        ]);
    }

    /**
     * @return non-empty-string
     */
    public static function webSocketImage(): string
    {
        return self::forPaths([
            'apps/reverb',
            'docker/orbit-reverb',
        ], [
            'apps/reverb/vendor',
            'apps/reverb/bootstrap/cache',
            'apps/reverb/storage/framework',
            'apps/reverb/storage/logs',
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludedPaths
     * @return non-empty-string
     */
    private static function forPaths(array $paths, array $excludedPaths): string
    {
        $files = [];

        foreach ($paths as $path) {
            $absolutePath = repo_path($path);

            if (is_file($absolutePath)) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($absolutePath)) {
                $files[] = "{$path}\0missing";

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relativePath = self::relativePath($file->getPathname());

                if ($relativePath === null || self::isExcluded($relativePath, $excludedPaths)) {
                    continue;
                }

                if (str_contains($relativePath, '/.env') && ! str_ends_with($relativePath, '/.env.example')) {
                    continue;
                }

                $files[] = $relativePath;
            }
        }

        sort($files);

        $context = hash_init('sha256');

        foreach ($files as $file) {
            $absolutePath = repo_path($file);
            hash_update($context, "{$file}\0");

            if (! is_file($absolutePath)) {
                hash_update($context, "missing\0");

                continue;
            }

            hash_update($context, (string) filesize($absolutePath));
            hash_update($context, "\0");
            hash_update_file($context, $absolutePath);
            hash_update($context, "\0");
        }

        return hash_final($context);
    }

    /**
     * @param  list<string>  $excludedPaths
     */
    private static function isExcluded(string $path, array $excludedPaths): bool
    {
        return array_any(
            $excludedPaths,
            fn ($excludedPath) => $path === $excludedPath || str_starts_with($path, "{$excludedPath}/"),
        );
    }

    private static function relativePath(string $path): ?string
    {
        $root = rtrim(repo_path(), '/').'/';

        if (! str_starts_with($path, $root)) {
            return null;
        }

        return substr($path, strlen($root));
    }
}
