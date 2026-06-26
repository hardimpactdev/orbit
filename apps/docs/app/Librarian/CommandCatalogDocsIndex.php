<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogDocsIndex
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    /**
     * @return array<string, string>
     */
    public function commandDirectoriesBySlug(): array
    {
        $directories = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $directories[$this->docs->commandName($commandDirectory)] = $commandDirectory;
            }
        }

        ksort($directories);

        return $directories;
    }

    /**
     * @return array{
     *     directory: ?string,
     *     public: ?string,
     *     technical: ?string,
     *     repo_directory: ?string,
     *     repo_public: ?string,
     *     repo_technical: ?string,
     * }
     */
    public function docsEntry(CliCommand $command, ?string $directory): array
    {
        if ($directory === null) {
            return [
                'directory' => null,
                'public' => null,
                'technical' => null,
                'repo_directory' => null,
                'repo_public' => null,
                'repo_technical' => null,
            ];
        }

        $public = "{$directory}/{$command->slug()}.md";
        $technical = "{$directory}/technical/1_{$command->slug()}.md";
        $directoryPath = $this->contentRelativePath($directory);
        $publicPath = is_file($public) ? $this->contentRelativePath($public) : null;
        $technicalPath = is_file($technical) ? $this->contentRelativePath($technical) : null;

        return [
            'directory' => $directoryPath,
            'public' => $publicPath,
            'technical' => $technicalPath,
            'repo_directory' => $this->repoRelativeContentPath($directoryPath),
            'repo_public' => $publicPath === null ? null : $this->repoRelativeContentPath($publicPath),
            'repo_technical' => $technicalPath === null ? null : $this->repoRelativeContentPath($technicalPath),
        ];
    }

    /**
     * @return list<string>
     */
    public function linkedTestFiles(string $directory): array
    {
        $tests = [];

        foreach ($this->docs->markdownFiles("{$directory}/technical", recursive: false) as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $matches = [];
            preg_match_all('/`((?:apps|packages)\/[^`]+Test\.php)`/', $contents, $matches);

            foreach ($matches[1] ?? [] as $path) {
                $tests[] = $path;
            }
        }

        $tests = array_values(array_unique($tests));
        sort($tests);

        return $tests;
    }

    private function contentRelativePath(string $path): string
    {
        $docsRoot = rtrim(
            string: str_replace(search: '\\', replace: '/', subject: $this->docs->docsRoot()),
            characters: '/',
        );
        $path = rtrim(string: str_replace(search: '\\', replace: '/', subject: $path), characters: '/');

        if ($path === $docsRoot) {
            return '.';
        }

        if (str_starts_with($path, "{$docsRoot}/")) {
            return substr($path, strlen($docsRoot) + 1);
        }

        return $path;
    }

    private function repoRelativeContentPath(string $path): string
    {
        return $path === '.'
            ? 'apps/docs/content'
            : "apps/docs/content/{$path}";
    }
}
