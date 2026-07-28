<?php

declare(strict_types=1);

use App\Services\Processes\LocalRuntimeDependencies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

/**
 * @mago-expect lint:halstead
 */
describe('internal runtime dependencies command', function (): void {
    beforeEach(function (): void {
        $this->path = sys_get_temp_dir().'/orbit-runtime-dependencies-'.bin2hex(random_bytes(8));
        mkdir($this->path);
    });

    afterEach(function (): void {
        delete_runtime_dependency_fixture($this->path);
    });

    it('detects only reconstructable missing dependency families and source activity', function (): void {
        file_put_contents(filename: $this->path.'/composer.json', data: '{}');
        file_put_contents(filename: $this->path.'/composer.lock', data: '{}');
        file_put_contents(filename: $this->path.'/package.json', data: '{}');
        file_put_contents(filename: $this->path.'/package-lock.json', data: '{}');
        file_put_contents(filename: $this->path.'/app.php', data: '<?php');

        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'app.php'] as $file) {
            touch(filename: $this->path.'/'.$file, mtime: 1_767_268_800);
        }

        $state = app(LocalRuntimeDependencies::class)->inspect($this->path);

        expect($state)
            ->toMatchArray([
                'source_activity_at' => 1_767_268_800,
                'dependencies' => [
                    [
                        'key' => 'composer',
                        'label' => 'Installing PHP dependencies',
                        'present' => false,
                        'reconstructable' => true,
                    ],
                    [
                        'key' => 'npm',
                        'label' => 'Installing frontend dependencies',
                        'present' => false,
                        'reconstructable' => true,
                    ],
                ],
            ]);
    });

    it('includes the latest git commit in source activity without traversing git internals', function (): void {
        file_put_contents(filename: $this->path.'/composer.json', data: '{}');
        file_put_contents(filename: $this->path.'/composer.lock', data: '{}');
        touch(filename: $this->path.'/composer.json', mtime: 1_767_268_700);
        touch(filename: $this->path.'/composer.lock', mtime: 1_767_268_700);
        Process::fake([
            '*' => Process::result(output: "1767268800\n"),
        ]);

        $state = app(LocalRuntimeDependencies::class)->inspect($this->path);

        expect($state['source_activity_at'])->toBe(1_767_268_800);

        Process::assertRan(
            fn ($process): bool => (
                $process->command === [
                    'git',
                    '-C',
                    realpath($this->path),
                    'log',
                    '-1',
                    '--format=%ct',
                ]
            ),
        );
    });

    it('rejects a missing operation token before inspecting a path', function (): void {
        $exitCode = Artisan::call('internal:runtime-dependencies', [
            'action' => 'inspect',
            'path' => $this->path,
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('prunes contained dependency directories but preserves lockfiles and caches', function (): void {
        file_put_contents(filename: $this->path.'/composer.json', data: '{}');
        file_put_contents(filename: $this->path.'/composer.lock', data: '{}');
        file_put_contents(filename: $this->path.'/package.json', data: '{}');
        file_put_contents(filename: $this->path.'/package-lock.json', data: '{}');
        mkdir($this->path.'/vendor/package', recursive: true);
        mkdir($this->path.'/node_modules/package', recursive: true);
        file_put_contents(filename: $this->path.'/vendor/package/file.php', data: '<?php');
        file_put_contents(filename: $this->path.'/node_modules/package/file.js', data: 'export {};');

        $result = app(LocalRuntimeDependencies::class)->prune($this->path);

        expect($result['pruned'])
            ->toBe(['composer', 'npm'])
            ->and(is_dir($this->path.'/vendor'))
            ->toBeFalse()
            ->and(is_dir($this->path.'/node_modules'))
            ->toBeFalse()
            ->and(is_file($this->path.'/composer.lock'))
            ->toBeTrue()
            ->and(is_file($this->path.'/package-lock.json'))
            ->toBeTrue();
    });

    it('reports failure after preserving a partially pruned dependency state', function (): void {
        file_put_contents(filename: $this->path.'/composer.json', data: '{}');
        file_put_contents(filename: $this->path.'/composer.lock', data: '{}');
        file_put_contents(filename: $this->path.'/package.json', data: '{}');
        file_put_contents(filename: $this->path.'/package-lock.json', data: '{}');
        mkdir($this->path.'/vendor/package', recursive: true);
        mkdir($this->path.'/node_modules/package', recursive: true);
        $calls = 0;
        File::shouldReceive('deleteDirectory')
            ->twice()
            ->andReturnUsing(function (string $path) use (&$calls): bool {
                $calls++;

                return $calls === 1
                    ? new Filesystem()->deleteDirectory($path)
                    : false;
            });

        expect(fn () => app(LocalRuntimeDependencies::class)->prune($this->path))
            ->toThrow(
                exception: \App\Services\Processes\LocalRuntimeDependenciesFailure::class,
                exceptionMessage: 'A generated dependency directory could not be removed.',
            );

        expect(is_dir($this->path.'/vendor'))
            ->toBeFalse()
            ->and(is_dir($this->path.'/node_modules'))
            ->toBeTrue();
    });

    it('refuses to prune dependency symlinks or directories without deterministic lockfiles', function (): void {
        $outside = sys_get_temp_dir().'/orbit-runtime-dependencies-outside-'.bin2hex(random_bytes(8));
        mkdir($outside);
        symlink($outside, $this->path.'/vendor');
        mkdir($this->path.'/node_modules');

        try {
            $result = app(LocalRuntimeDependencies::class)->prune($this->path);

            expect($result['pruned'])
                ->toBeEmpty()
                ->and(is_link($this->path.'/vendor'))
                ->toBeTrue()
                ->and(is_dir($this->path.'/node_modules'))
                ->toBeTrue();
        } finally {
            unlink($this->path.'/vendor');
            rmdir($outside);
        }
    });

    it('restores each detected family with its lockfile-specific frozen command', function (): void {
        file_put_contents(filename: $this->path.'/composer.json', data: '{}');
        file_put_contents(filename: $this->path.'/composer.lock', data: '{}');
        file_put_contents(filename: $this->path.'/package.json', data: '{}');
        file_put_contents(filename: $this->path.'/package-lock.json', data: '{}');

        $commands = [];
        Process::fake(function ($process) use (&$commands) {
            $commands[] = [
                'command' => $process->command,
                'path' => $process->path,
            ];

            return Process::result();
        });

        $dependencies = app(LocalRuntimeDependencies::class);

        expect($dependencies->restore($this->path, 'composer')['restored'])
            ->toBe('composer')
            ->and($dependencies->restore($this->path, 'npm')['restored'])
            ->toBe('npm');

        expect($commands)->toBe([
            [
                'command' => ['composer', 'install', '--no-interaction', '--prefer-dist'],
                'path' => realpath($this->path),
            ],
            [
                'command' => ['npm', 'ci'],
                'path' => realpath($this->path),
            ],
        ]);
    });
});

function delete_runtime_dependency_fixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isLink() || $file->isFile()) {
            unlink($file->getPathname());

            continue;
        }

        rmdir($file->getPathname());
    }

    rmdir($path);
}
