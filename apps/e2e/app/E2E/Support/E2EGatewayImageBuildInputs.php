<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * @mago-expect lint:cyclomatic-complexity -- This class validates and inventories the complete gateway image input contract.
 * @mago-expect lint:kan-defect -- This class validates and inventories the complete gateway image input contract.
 */
final class E2EGatewayImageBuildInputs
{
    public const string ManifestPath = 'docker/orbit-gateway/Dockerfile.inputs';

    /**
     * @return list<string>
     */
    public static function paths(?string $root = null): array
    {
        $root = rtrim($root ?? repo_path(), characters: '/');
        $manifest = "{$root}/".self::ManifestPath;

        if (! is_readable($manifest)) {
            throw new RuntimeException("Gateway image build-input manifest is not readable: {$manifest}");
        }

        $contents = file_get_contents($manifest);

        if ($contents === false) {
            throw new RuntimeException("Gateway image build-input manifest is not readable: {$manifest}");
        }

        $paths = [];
        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            throw new RuntimeException("Gateway image build-input manifest cannot be parsed: {$manifest}");
        }

        foreach ($lines as $line) {
            $path = trim($line);

            if ($path === '') {
                continue;
            }

            if (
                str_starts_with($path, '/')
                || str_contains($path, '\\')
                || in_array('..', explode(separator: '/', string: $path), strict: true)
            ) {
                throw new RuntimeException("Gateway image build-input manifest contains an invalid path: {$path}");
            }

            if (in_array($path, $paths, strict: true)) {
                throw new RuntimeException("Gateway image build-input manifest contains a duplicate path: {$path}");
            }

            $paths[] = $path;
        }

        if ($paths === []) {
            throw new RuntimeException('Gateway image build-input manifest is empty.');
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    public static function stagingPaths(?string $root = null): array
    {
        $root = rtrim($root ?? repo_path(), characters: '/');
        $paths = self::paths($root);

        return array_values(array_filter($paths, static function (string $candidate) use ($paths, $root): bool {
            foreach ($paths as $parent) {
                if ($parent === $candidate || ! is_dir("{$root}/{$parent}")) {
                    continue;
                }

                if (str_starts_with($candidate, "{$parent}/")) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array{hash: non-empty-string, files: array<string, string>}
     */
    public static function inventory(?string $root = null): array
    {
        $root = rtrim($root ?? repo_path(), characters: '/');
        $files = [];

        foreach (self::paths($root) as $path) {
            $absolute = "{$root}/{$path}";

            if (is_file($absolute)) {
                $files[$path] = self::fileDigest($absolute);

                continue;
            }

            if (! is_dir($absolute)) {
                $files[$path] = 'missing';

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen($root)), characters: '/');

                if (self::isIgnored($relative)) {
                    continue;
                }

                $files[$relative] = self::fileDigest($file->getPathname());
            }
        }

        ksort($files);

        /** @var non-empty-string $hash */
        $hash = hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'hash' => $hash,
            'files' => $files,
        ];
    }

    /**
     * @return non-empty-string
     */
    public static function fingerprint(?string $root = null): string
    {
        return self::inventory($root)['hash'];
    }

    private static function isIgnored(string $path): bool
    {
        $basename = basename($path);

        if (str_starts_with($basename, '.env') && $basename !== '.env.example') {
            return true;
        }

        if (preg_match('#^apps/gateway/database/[^/]+\.sqlite(?:-.+)?$#', $path) === 1) {
            return true;
        }

        return array_any(
            ['/vendor/', '/node_modules/', '/tests/', '/storage/', '/bootstrap/cache/', '/build/', '/builds/'],
            static fn (string $segment): bool => str_contains("/{$path}", $segment),
        );
    }

    private static function fileDigest(string $path): string
    {
        $digest = hash_file('sha256', $path);

        return $digest === false ? '' : $digest;
    }
}
