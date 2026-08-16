<?php

declare(strict_types=1);

namespace App\Librarian;

use FilesystemIterator;
use HardImpact\Librarian\Docs\DocsConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class OrbitCommandDocs
{
    /** @var array<string, true> */
    private array $directories;

    /** @var array<string, true> */
    private array $files;

    /** @var array<string, string> */
    private array $contents;

    /** @var array<string, list<string>> */
    private array $directoriesBySnapshotKey;

    /** @var array<string, list<string>> */
    private array $markdownFilesBySnapshotKey;

    public function __construct(
        private DocsConfig $config,
    ) {
        [
            $this->directories,
            $this->files,
            $this->contents,
            $this->directoriesBySnapshotKey,
            $this->markdownFilesBySnapshotKey,
        ] =
            $this->buildFilesystemIndex();
    }

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
        return $this->markdownFilesBySnapshotKey[$this->snapshotKey($directory, $recursive)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function directories(string $directory, bool $recursive = false): array
    {
        return $this->directoriesBySnapshotKey[$this->snapshotKey($directory, $recursive)] ?? [];
    }

    public function isDirectory(string $path): bool
    {
        return array_key_exists($this->normalizePath($path), $this->directories);
    }

    public function isFile(string $path): bool
    {
        return array_key_exists($this->normalizePath($path), $this->files);
    }

    public function exists(string $path): bool
    {
        return $this->isDirectory($path) || $this->isFile($path);
    }

    public function contents(string $path): string
    {
        return $this->contents[$this->normalizePath($path)] ?? '';
    }

    /**
     * Finding paths use the Librarian canonical `docs/` namespace so
     * `librarian:lint --path` filtering matches them; the on-disk docs
     * directory name (`content`) must not leak into finding paths.
     */
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
        return "{$this->config->path}/domains";
    }

    public function docsRoot(): string
    {
        return $this->config->path;
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
        return $this->directories($directory);
    }

    /**
     * @return array{
     *     array<string, true>,
     *     array<string, true>,
     *     array<string, string>,
     *     array<string, list<string>>,
     *     array<string, list<string>>,
     * }
     */
    private function buildFilesystemIndex(): array
    {
        $docsRoot = $this->normalizePath($this->config->path);

        if (! is_dir($docsRoot)) {
            return [[], [], [], [], []];
        }

        [$directories, $files, $contents] = $this->scanFilesystem($docsRoot);

        return [
            $directories,
            $files,
            $contents,
            $this->directorySnapshots($directories, $docsRoot),
            $this->markdownFileSnapshots($directories, $files, $docsRoot),
        ];
    }

    /**
     * @return array{array<string, true>, array<string, true>, array<string, string>}
     */
    private function scanFilesystem(string $docsRoot): array
    {
        $directories = [$docsRoot => true];
        $files = [];
        $contents = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            $path = $this->normalizePath($entry->getPathname());

            if ($entry->isDir()) {
                $directories[$path] = true;

                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            $files[$path] = true;
            $fileContents = file_get_contents($path);
            $contents[$path] = $fileContents === false ? '' : $fileContents;
        }

        return [$directories, $files, $contents];
    }

    /**
     * @param array<string, true> $directories
     * @return array<string, list<string>>
     */
    private function directorySnapshots(array $directories, string $docsRoot): array
    {
        $snapshots = $this->emptySnapshots($directories);

        foreach (array_keys($directories) as $directory) {
            if ($directory === $docsRoot) {
                continue;
            }

            $parent = $this->normalizePath(dirname($directory));
            $snapshots[$this->snapshotKey($parent, false)][] = $directory;

            foreach ($this->ancestorsWithinDocs($parent, $docsRoot) as $ancestor) {
                $snapshots[$this->snapshotKey($ancestor, true)][] = $directory;
            }
        }

        return $this->sortSnapshots($snapshots);
    }

    /**
     * @param array<string, true> $directories
     * @param array<string, true> $files
     * @return array<string, list<string>>
     */
    private function markdownFileSnapshots(array $directories, array $files, string $docsRoot): array
    {
        $snapshots = $this->emptySnapshots($directories);

        foreach (array_keys($files) as $file) {
            if (! str_ends_with($file, '.md')) {
                continue;
            }

            $parent = $this->normalizePath(dirname($file));
            $snapshots[$this->snapshotKey($parent, false)][] = $file;

            foreach ($this->ancestorsWithinDocs($parent, $docsRoot) as $ancestor) {
                $snapshots[$this->snapshotKey($ancestor, true)][] = $file;
            }
        }

        return $this->sortSnapshots($snapshots);
    }

    /**
     * @param array<string, true> $directories
     * @return array<string, list<string>>
     */
    private function emptySnapshots(array $directories): array
    {
        $snapshots = [];

        foreach (array_keys($directories) as $directory) {
            $snapshots[$this->snapshotKey($directory, false)] = [];
            $snapshots[$this->snapshotKey($directory, true)] = [];
        }

        return $snapshots;
    }

    /**
     * @param array<string, list<string>> $snapshots
     * @return array<string, list<string>>
     */
    private function sortSnapshots(array $snapshots): array
    {
        foreach ($snapshots as &$paths) {
            sort($paths);
        }
        unset($paths);

        return $snapshots;
    }

    /**
     * @return list<string>
     */
    private function ancestorsWithinDocs(string $directory, string $docsRoot): array
    {
        $ancestors = [];
        $current = $directory;

        while ($current === $docsRoot || str_starts_with($current, "{$docsRoot}/")) {
            $ancestors[] = $current;

            if ($current === $docsRoot) {
                break;
            }

            $parent = $this->normalizePath(dirname($current));

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return $ancestors;
    }

    private function snapshotKey(string $directory, bool $recursive): string
    {
        $mode = $recursive ? 'recursive' : 'direct';

        return $this->normalizePath($directory)."\0{$mode}";
    }

    private function isFamilyDirectory(string $directory): bool
    {
        return (
            $this->isDirectory($directory)
            && dirname($this->normalizePath($directory)) === $this->normalizePath($this->commandsRoot())
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1
        );
    }

    private function isCommandDirectory(string $directory, string $familyDirectory): bool
    {
        return (
            $this->isDirectory($directory)
            && dirname($this->normalizePath($directory)) === $this->normalizePath($familyDirectory)
            && preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*$/', basename($directory)) === 1
        );
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
