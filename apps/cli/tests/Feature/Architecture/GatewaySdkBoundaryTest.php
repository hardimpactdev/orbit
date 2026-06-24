<?php

declare(strict_types=1);

it('keeps CLI-owned code behind the Orbit SDK boundary', function (): void {
    $repoRoot = dirname(__DIR__, 3);
    $needle = 'Sa'.'loon';

    foreach (cliSdkBoundaryPhpFiles([
        "{$repoRoot}/app",
        "{$repoRoot}/tests",
    ]) as $path) {
        expect(file_get_contents($path) ?: '')
            ->not
            ->toContain($needle, "{$path} imports SDK HTTP-client internals directly.");
    }
});

/**
 * @param  list<string>  $roots
 * @return list<string>
 */
function cliSdkBoundaryPhpFiles(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
