<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class CommandDocsLintContext
{
    public function __construct(
        public string $repositoryRoot,
        public string $scanRoot,
    ) {}

    /**
     * @return list<string>
     */
    public function convertedFamilyDirectories(): array
    {
        $enclosingFamilyDirectory = $this->enclosingConvertedFamilyDirectory($this->scanRoot);

        if ($enclosingFamilyDirectory !== null) {
            return [$enclosingFamilyDirectory];
        }

        $directories = [];

        foreach ($this->immediateDirectories($this->scanRoot) as $directory) {
            if ($this->isConvertedFamilyDirectory($directory)) {
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
        $enclosingCommandDirectory = $this->enclosingCommandDirectory($this->scanRoot, $familyDirectory);

        if ($enclosingCommandDirectory !== null) {
            return [$enclosingCommandDirectory];
        }

        if (is_file($this->scanRoot) && dirname($this->scanRoot) === $familyDirectory) {
            return [];
        }

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
    public function technicalMarkdownFiles(string $technicalDirectory): array
    {
        return $this->markdownFiles($technicalDirectory, recursive: false);
    }

    public function read(string $path): string
    {
        return file_get_contents($path) ?: '';
    }

    public function relativePath(string $path): string
    {
        if (str_starts_with($path, "{$this->repositoryRoot}/")) {
            return substr($path, strlen($this->repositoryRoot) + 1);
        }

        return $path;
    }

    public function isConvertedFamilyDirectory(string $directory): bool
    {
        return is_dir($directory)
            && dirname($this->normalizePath($directory)) === $this->commandsRoot()
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1;
    }

    /**
     * @return list<string>
     */
    public function markdownFiles(string $directory, bool $recursive = true): array
    {
        if (is_file($directory)) {
            if ($this->isMarkdownFile($directory) && $this->isWithinScanRoot($directory)) {
                return [$directory];
            }

            return [];
        }

        if (! is_dir($directory) || ! $this->overlapsScanRoot($directory)) {
            return [];
        }

        $files = [];

        if (! $recursive) {
            foreach (scandir($directory) ?: [] as $entry) {
                $path = "{$directory}/{$entry}";

                if ($this->isMarkdownFile($path) && $this->isWithinScanRoot($path)) {
                    $files[] = $path;
                }
            }

            sort($files);

            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if ($this->isMarkdownFile($path) && $this->isWithinScanRoot($path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
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

    private function commandsRoot(): string
    {
        return "{$this->repositoryRoot}/docs/commands";
    }

    private function enclosingConvertedFamilyDirectory(string $path): ?string
    {
        $directory = is_file($path) ? dirname($path) : $path;

        while ($directory !== '' && $directory !== dirname($directory)) {
            if ($this->isConvertedFamilyDirectory($directory)) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }

    private function enclosingCommandDirectory(string $path, string $familyDirectory): ?string
    {
        $directory = is_file($path) ? dirname($path) : $path;
        $familyDirectory = $this->normalizePath($familyDirectory);

        while ($this->isWithin($directory, $familyDirectory)) {
            if ($this->isCommandDirectory($directory, $familyDirectory)) {
                return $directory;
            }

            if ($this->normalizePath($directory) === $familyDirectory) {
                break;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }

    private function isCommandDirectory(string $directory, string $familyDirectory): bool
    {
        return is_dir($directory)
            && dirname($this->normalizePath($directory)) === $this->normalizePath($familyDirectory)
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1;
    }

    private function isMarkdownFile(string $path): bool
    {
        return is_file($path) && str_ends_with($path, '.md');
    }

    private function isWithinScanRoot(string $path): bool
    {
        $scanRoot = $this->normalizePath($this->scanRoot);
        $path = $this->normalizePath($path);

        if (is_file($scanRoot)) {
            return $path === $scanRoot;
        }

        return $this->isWithin($path, $scanRoot);
    }

    private function overlapsScanRoot(string $path): bool
    {
        $scanRoot = $this->normalizePath($this->scanRoot);
        $path = $this->normalizePath($path);

        return $this->isWithin($path, $scanRoot) || $this->isWithin($scanRoot, $path);
    }

    private function isWithin(string $path, string $directory): bool
    {
        $path = $this->normalizePath($path);
        $directory = $this->normalizePath($directory);

        return $path === $directory || str_starts_with($path, "{$directory}/");
    }

    private function normalizePath(string $path): string
    {
        return rtrim($path, '/');
    }
}
