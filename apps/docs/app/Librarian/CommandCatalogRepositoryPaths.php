<?php

declare(strict_types=1);

namespace App\Librarian;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class CommandCatalogRepositoryPaths
{
    private string $repositoryRoot;

    public function __construct(string $repositoryRoot)
    {
        $this->repositoryRoot = rtrim(
            string: str_replace(search: '\\', replace: '/', subject: $repositoryRoot),
            characters: '/',
        );
    }

    /**
     * @return list<string>
     */
    public function phpFiles(string $directory): array
    {
        $absoluteDirectory = $this->absolutePath($directory);

        if (! is_dir($absoluteDirectory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $this->relativePath($file->getPathname());
        }

        sort($files);

        return $files;
    }

    public function absolutePath(string $path): string
    {
        $path = str_replace(search: '\\', replace: '/', subject: $path);

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return "{$this->repositoryRoot}/{$path}";
    }

    public function gatewayClassPath(string $class): ?string
    {
        if (! str_starts_with($class, 'App\\')) {
            return null;
        }

        return (
            'apps/gateway/app/'
            .str_replace(
                search: '\\',
                replace: '/',
                subject: substr($class, strlen('App\\')),
            )
            .'.php'
        );
    }

    public function sdkClassPath(string $class): ?string
    {
        $prefix = 'Orbit\\Sdk\\Laravel\\';

        if (! str_starts_with($class, $prefix)) {
            return null;
        }

        return (
            'packages/sdk/src/'
            .str_replace(
                search: '\\',
                replace: '/',
                subject: substr($class, strlen($prefix)),
            )
            .'.php'
        );
    }

    private function relativePath(string $path): string
    {
        $path = str_replace(search: '\\', replace: '/', subject: $path);

        if (str_starts_with($path, "{$this->repositoryRoot}/")) {
            return substr($path, strlen($this->repositoryRoot) + 1);
        }

        return $path;
    }
}
