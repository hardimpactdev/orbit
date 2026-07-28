<?php

declare(strict_types=1);

namespace App\Services\Processes;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalRuntimeDependencies
{
    private const array EXCLUDED_SOURCE_DIRECTORIES = [
        '.git',
        '.next',
        'bootstrap/cache',
        'build',
        'dist',
        'node_modules',
        'public/build',
        'storage',
        'vendor',
    ];

    /**
     * @return array{
     *     source_activity_at: int,
     *     dependencies: list<array{
     *         key: string,
     *         label: string,
     *         present: bool,
     *         reconstructable: bool
     *     }>
     * }
     */
    public function inspect(mixed $path): array
    {
        $path = $this->sourcePath($path);
        $dependencies = [];

        if (is_file($path.'/composer.json')) {
            $dependencies[] = [
                'key' => 'composer',
                'label' => 'Installing PHP dependencies',
                'present' => is_dir($path.'/vendor') && ! is_link($path.'/vendor'),
                'reconstructable' => is_file($path.'/composer.lock'),
            ];
        }

        if (is_file($path.'/package.json')) {
            $nodeFamily = $this->nodeFamily($path);
            $dependencies[] = [
                'key' => $nodeFamily ?? 'node',
                'label' => 'Installing frontend dependencies',
                'present' => is_dir($path.'/node_modules') && ! is_link($path.'/node_modules'),
                'reconstructable' => $nodeFamily !== null,
            ];
        }

        return [
            'source_activity_at' => $this->sourceActivityAt($path),
            'dependencies' => $dependencies,
        ];
    }

    /**
     * @return array{pruned: list<string>}
     */
    public function prune(mixed $path): array
    {
        $path = $this->sourcePath($path);
        $state = $this->inspect($path);
        $pruned = [];

        foreach ($state['dependencies'] as $dependency) {
            if (! $dependency['present'] || ! $dependency['reconstructable']) {
                continue;
            }

            $directory = $dependency['key'] === 'composer' ? 'vendor' : 'node_modules';

            if (! $this->mayDelete($path, $directory)) {
                continue;
            }

            if (! File::deleteDirectory($path.'/'.$directory)) {
                throw new LocalRuntimeDependenciesFailure(
                    errorCode: 'runtime_dependency_prune_failed',
                    message: 'A generated dependency directory could not be removed.',
                    meta: ['family' => $dependency['key']],
                );
            }

            $pruned[] = $dependency['key'];
        }

        return ['pruned' => $pruned];
    }

    /**
     * @return array{restored: string}
     */
    public function restore(mixed $path, mixed $family): array
    {
        $path = $this->sourcePath($path);

        if (! is_string($family)) {
            throw $this->invalidFamily();
        }

        $command = $this->restoreCommand($path, $family);
        $result = Process::path($path)
            ->timeout(900)
            ->run($command);

        if (! $result->successful()) {
            throw new LocalRuntimeDependenciesFailure(
                errorCode: 'runtime_dependency_restore_failed',
                message: 'A dependency install did not complete successfully.',
                meta: [
                    'family' => $family,
                    'exit_code' => $result->exitCode(),
                ],
            );
        }

        return ['restored' => $family];
    }

    /**
     * @return list<string>
     */
    private function restoreCommand(string $path, string $family): array
    {
        if ($family === 'composer' && is_file($path.'/composer.lock')) {
            return ['composer', 'install', '--no-interaction', '--prefer-dist'];
        }

        $nodeFamily = $this->nodeFamily($path);

        if ($nodeFamily === null || $family !== $nodeFamily) {
            throw $this->invalidFamily();
        }

        return match ($family) {
            'npm' => ['npm', 'ci'],
            'pnpm' => ['pnpm', 'install', '--frozen-lockfile'],
            'yarn' => ['yarn', 'install', '--frozen-lockfile'],
            'bun' => ['bun', 'install', '--frozen-lockfile'],
            default => throw $this->invalidFamily(),
        };
    }

    private function invalidFamily(): LocalRuntimeDependenciesFailure
    {
        return new LocalRuntimeDependenciesFailure(
            errorCode: 'validation_failed',
            message: 'The dependency family is not reconstructable for this source tree.',
            meta: ['field' => 'family'],
        );
    }

    private function nodeFamily(string $path): ?string
    {
        $families = array_values(array_filter([
            is_file($path.'/package-lock.json') ? 'npm' : null,
            is_file($path.'/pnpm-lock.yaml') ? 'pnpm' : null,
            is_file($path.'/yarn.lock') ? 'yarn' : null,
            is_file($path.'/bun.lock') || is_file($path.'/bun.lockb') ? 'bun' : null,
        ]));

        return count($families) === 1 ? $families[0] : null;
    }

    private function sourceActivityAt(string $path): int
    {
        $latest = 0;
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function (mixed $file) use ($path): bool {
                if (! $file instanceof SplFileInfo) {
                    return false;
                }

                $relativePath = ltrim(
                    substr($file->getPathname(), strlen($path)),
                    characters: '/',
                );

                return ! $file->isDir() || ! $this->isExcludedSourcePath($relativePath);
            },
        );
        $iterator = new RecursiveIteratorIterator($filter);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relativePath = ltrim(
                substr($file->getPathname(), strlen($path)),
                characters: '/',
            );

            if ($file->isLink() || $this->isExcludedSourcePath($relativePath)) {
                continue;
            }

            $fileMtime = $file->getMTime();

            if (is_int($fileMtime) && $fileMtime > $latest) {
                $latest = $fileMtime;
            }
        }

        $gitActivityAt = $this->gitActivityAt($path);

        if ($gitActivityAt > $latest) {
            $latest = $gitActivityAt;
        }

        $directoryMtime = filemtime($path);

        if ($latest > 0) {
            return $latest;
        }

        return $directoryMtime === false ? 0 : $directoryMtime;
    }

    private function gitActivityAt(string $path): int
    {
        try {
            $result = Process::timeout(5)->run([
                'git',
                '-C',
                $path,
                'log',
                '-1',
                '--format=%ct',
            ]);
        } catch (Throwable) {
            return 0;
        }

        $timestamp = trim($result->output());

        return $result->successful() && ctype_digit($timestamp) ? (int) $timestamp : 0;
    }

    private function isExcludedSourcePath(string $relativePath): bool
    {
        return array_any(
            self::EXCLUDED_SOURCE_DIRECTORIES,
            static fn (string $directory): bool => $relativePath === $directory
            || str_starts_with($relativePath, $directory.'/'),
        );
    }

    private function mayDelete(string $path, string $directory): bool
    {
        $target = $path.'/'.$directory;

        if (! is_dir($target) || is_link($target)) {
            return false;
        }

        $realPath = realpath($path);
        $realTarget = realpath($target);

        return (
            is_string($realPath)
            && is_string($realTarget)
            && str_starts_with($realTarget.'/', rtrim($realPath, characters: '/').'/')
            && $realTarget !== $realPath
        );
    }

    private function sourcePath(mixed $value): string
    {
        if (
            ! is_string($value)
            || $value === ''
            || ! str_starts_with($value, '/')
            || str_contains($value, "\0")
        ) {
            throw new LocalRuntimeDependenciesFailure(
                errorCode: 'validation_failed',
                message: 'The runtime source path must be absolute.',
                meta: ['field' => 'path'],
            );
        }

        $path = realpath($value);

        if (! is_string($path) || ! is_dir($path)) {
            throw new LocalRuntimeDependenciesFailure(
                errorCode: 'runtime_source_missing',
                message: 'The runtime source path does not exist.',
            );
        }

        return rtrim($path, characters: '/');
    }
}
