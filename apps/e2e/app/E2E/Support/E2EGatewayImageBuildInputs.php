<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * @mago-expect lint:cyclomatic-complexity -- This class validates and inventories the complete gateway image input contract.
 * @mago-expect lint:kan-defect -- This class validates and inventories the complete gateway image input contract.
 * @mago-expect lint:too-many-methods -- The validation steps stay private to the single gateway image input authority.
 */
final class E2EGatewayImageBuildInputs
{
    public const string ManifestPath = 'docker/orbit-gateway/Dockerfile.inputs';

    /**
     * @return list<string>
     */
    public static function paths(?string $root = null): array
    {
        $root = self::canonicalRoot($root);
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
            $declaration = trim($line);

            if ($declaration === '') {
                continue;
            }

            $path = self::canonicalManifestPath($declaration);

            if (in_array($path, $paths, strict: true)) {
                throw new RuntimeException(
                    "Gateway image build-input manifest contains a duplicate canonical path: {$path}",
                );
            }

            self::assertDeclaredInput($root, $path);
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
        $root = self::canonicalRoot($root);
        $paths = self::paths($root);

        foreach ($paths as $path) {
            self::filesForPath($root, $path);
        }

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
        $root = self::canonicalRoot($root);
        $files = [];

        foreach (self::paths($root) as $path) {
            foreach (self::filesForPath($root, $path) as $relative => $absolute) {
                $files[$relative] = self::fileDigest($absolute, $relative);
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

    private static function canonicalRoot(?string $root): string
    {
        $root ??= repo_path();
        $canonical = realpath($root);

        if ($canonical === false || ! is_dir($canonical) || ! is_readable($canonical)) {
            throw new RuntimeException("Gateway image repository root is not a readable directory: {$root}");
        }

        return rtrim($canonical, characters: '/');
    }

    private static function canonicalManifestPath(string $path): string
    {
        if (str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new RuntimeException("Gateway image build-input manifest contains an invalid path: {$path}");
        }

        $segments = [];

        foreach (explode(separator: '/', string: $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException("Gateway image build-input manifest contains an invalid path: {$path}");
            }

            $segments[] = $segment;
        }

        $canonical = implode('/', $segments);

        if ($canonical === '') {
            throw new RuntimeException("Gateway image build-input manifest contains an invalid path: {$path}");
        }

        return $canonical;
    }

    private static function assertDeclaredInput(string $root, string $path): void
    {
        $absolute = "{$root}/{$path}";
        clearstatcache(clear_realpath_cache: true, filename: $absolute);

        if (! file_exists($absolute) && ! is_link($absolute)) {
            throw new RuntimeException("Gateway image declared input does not exist: {$path}");
        }

        $resolved = realpath($absolute);

        if ($resolved === false) {
            throw new RuntimeException("Gateway image declared input cannot be resolved: {$path}");
        }

        if (! self::isContained($root, $resolved)) {
            throw new RuntimeException("Gateway image declared input resolves outside the repository: {$path}");
        }

        if ($resolved !== $absolute) {
            throw new RuntimeException("Gateway image symbolic-link aliases are not allowed: {$path}");
        }

        if (! is_readable($absolute)) {
            throw new RuntimeException("Gateway image declared input is not readable: {$path}");
        }

        if (! is_file($absolute) && ! is_dir($absolute)) {
            throw new RuntimeException("Gateway image declared input has an unsupported type: {$path}");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function filesForPath(string $root, string $path): array
    {
        $absolute = "{$root}/{$path}";

        if (is_file($absolute)) {
            return [$path => $absolute];
        }

        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen($root)), characters: '/');
                self::assertInventoryEntry($root, $relative, $file);

                if ($file->isFile()) {
                    $files[$relative] = $file->getPathname();
                }
            }
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException(
                "Gateway image declared input is not readable: {$path}",
                previous: $exception,
            );
        }

        ksort($files);

        return $files;
    }

    private static function assertInventoryEntry(string $root, string $path, SplFileInfo $file): void
    {
        $resolved = $file->getRealPath();

        if ($resolved === false) {
            throw new RuntimeException("Gateway image input cannot be resolved: {$path}");
        }

        if (! self::isContained($root, $resolved)) {
            throw new RuntimeException("Gateway image input resolves outside the repository: {$path}");
        }

        if ($file->isLink() || $resolved !== $file->getPathname()) {
            throw new RuntimeException("Gateway image symbolic-link aliases are not allowed: {$path}");
        }

        if (! $file->isReadable()) {
            throw new RuntimeException("Gateway image input is not readable: {$path}");
        }

        if (! $file->isFile() && ! $file->isDir()) {
            throw new RuntimeException("Gateway image input has an unsupported type: {$path}");
        }
    }

    private static function isContained(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, "{$root}/");
    }

    private static function fileDigest(string $absolute, string $relative): string
    {
        if (! is_readable($absolute)) {
            throw new RuntimeException("Gateway image input is not readable: {$relative}");
        }

        $digest = hash_file('sha256', $absolute);

        if ($digest === false) {
            throw new RuntimeException("Gateway image input cannot be fingerprinted: {$relative}");
        }

        return $digest;
    }
}
