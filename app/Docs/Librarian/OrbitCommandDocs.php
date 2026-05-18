<?php

declare(strict_types=1);

namespace App\Docs\Librarian;

use HardImpact\Librarian\Docs\DocsConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class OrbitCommandDocs
{
    public function __construct(
        private DocsConfig $config,
    ) {}

    /**
     * @return list<string>
     */
    public function familyDirectories(): array
    {
        $directories = [];

        foreach ($this->immediateDirectories($this->commandsRoot()) as $directory) {
            if ($this->isFamilyDirectory($directory)) {
                $directories[] = $directory;
            }
        }

        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    public function commandDirectories(string $familyDirectory): array
    {
        $directories = [];

        foreach ($this->immediateDirectories($familyDirectory) as $directory) {
            if ($this->isCommandDirectory($directory, $familyDirectory)) {
                $directories[] = $directory;
            }
        }

        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    public function markdownFiles(string $directory, bool $recursive = true): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        if (! $recursive) {
            return $this->directMarkdownFiles($directory);
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    public function relativePath(string $path): string
    {
        $docsRoot = $this->normalizePath($this->config->path);
        $path = $this->normalizePath($path);

        if ($path === $docsRoot) {
            return 'docs';
        }

        if (str_starts_with($path, "{$docsRoot}/")) {
            return 'docs/'.substr($path, strlen($docsRoot) + 1);
        }

        return $path;
    }

    public function familyName(string $familyDirectory): string
    {
        return preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory)) ?? basename($familyDirectory);
    }

    public function commandName(string $commandDirectory): string
    {
        return preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory)) ?? basename($commandDirectory);
    }

    public function commandsRoot(): string
    {
        return "{$this->config->path}/commands";
    }

    public function repositoryRoot(): string
    {
        return dirname($this->config->path);
    }

    /**
     * @return list<string>
     */
    private function immediateDirectories(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $directories = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$directory}/{$entry}";

            if (is_dir($path)) {
                $directories[] = $path;
            }
        }

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function directMarkdownFiles(string $directory): array
    {
        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            $path = "{$directory}/{$entry}";

            if (is_file($path) && str_ends_with($entry, '.md')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function isFamilyDirectory(string $directory): bool
    {
        return is_dir($directory)
            && dirname($this->normalizePath($directory)) === $this->normalizePath($this->commandsRoot())
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1;
    }

    private function isCommandDirectory(string $directory, string $familyDirectory): bool
    {
        return is_dir($directory)
            && dirname($this->normalizePath($directory)) === $this->normalizePath($familyDirectory)
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
