<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/** @property string $path */
describe('GitRepository', function (): void {
    beforeEach(function (): void {
        configureProcessFacade();
        $this->path = temporaryPath('orbit-git-', 6);
        mkdir($this->path, 0700, true);
        git($this->path, ['init', '--quiet']);
        git($this->path, ['config', 'user.email', 'orbit@example.test']);
        git($this->path, ['config', 'user.name', 'Orbit']);
    });

    afterEach(function (): void {
        if (is_dir($this->path)) {
            git($this->path, ['clean', '-fdx']);
        }
    });

    it('resolves the canonical root once for each nested multi-file overlay inventory', function (): void {
        mkdir($this->path.'/one/two/three', 0700, true);
        file_put_contents($this->path.'/one/two/three/executable.sh', "#!/bin/sh\nprintf before\\n\n");
        chmod($this->path.'/one/two/three/executable.sh', 0755);
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'overlay base']);
        file_put_contents($this->path.'/one/two/three/executable.sh', "#!/bin/sh\nprintf after\\n\n");
        file_put_contents($this->path.'/one/two/three/untracked.txt', "untracked\n");

        $real = new ProcessFactory;
        $commands = [];
        Process::fake(function (PendingProcess $process) use ($real, &$commands) {
            $commands[] = $process->command;

            return $real
                ->path((string) $process->path)
                ->env($process->environment)
                ->input($process->input)
                ->run($process->command);
        });
        $repository = new GitRepository($this->path);

        $firstOverlay = $repository->dirtyOverlay();
        $inventoryRootResolutions = rootResolutionCount($commands);
        $commands = [];
        file_put_contents($this->path.'/one/two/three/fresh.txt', "fresh\n");
        $secondOverlay = $repository->dirtyOverlay();

        expect($firstOverlay?->paths)
            ->toBe([
                'one/two/three/executable.sh',
                'one/two/three/untracked.txt',
            ])
            ->and($inventoryRootResolutions)
            ->toBe(1)
            ->and(rootResolutionCount($commands))
            ->toBe(1)
            ->and($secondOverlay?->paths)
            ->toBe([
                'one/two/three/executable.sh',
                'one/two/three/fresh.txt',
                'one/two/three/untracked.txt',
            ])
            ->and($secondOverlay?->treeHash)
            ->not->toBe($firstOverlay?->treeHash);
    });

    it('refuses a dirty secret path', function (): void {
        file_put_contents($this->path.'/tracked.txt', "tracked\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'base']);
        mkdir($this->path.'/nested', 0700);
        file_put_contents($this->path.'/nested/.env.production', "SECRET=1\n");

        expect(fn (): ?\App\E2E\Value\DirtyOverlay => new GitRepository($this->path)->dirtyOverlay())
            ->toThrow(InvalidArgumentException::class, 'is prohibited');
    });

    it('refuses a dirty symlink', function (): void {
        file_put_contents($this->path.'/target.txt', "target\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'base']);
        symlink('target.txt', $this->path.'/link.txt');

        expect(fn (): ?\App\E2E\Value\DirtyOverlay => new GitRepository($this->path)->dirtyOverlay())
            ->toThrow(InvalidArgumentException::class, 'Dirty symlinks cannot be synchronized.');
    });

    it('retains the submodule overlay refusal', function (): void {
        file_put_contents($this->path.'/tracked.txt', "tracked\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'base']);
        $commit = git($this->path, ['rev-parse', 'HEAD']);
        git($this->path, ['update-index', '--add', '--cacheinfo', '160000,'.$commit.',module']);
        git($this->path, ['commit', '--quiet', '-m', 'gitlink']);
        file_put_contents($this->path.'/module', "regular file\n");

        expect(fn (): ?\App\E2E\Value\DirtyOverlay => new GitRepository($this->path)->dirtyOverlay())
            ->toThrow(InvalidArgumentException::class, 'Submodules cannot be synchronized.');
    });
});

/** @param list<string> $arguments */
function git(string $path, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Git fixture command failed.');
    }

    return trim(implode("\n", $output));
}

function configureProcessFacade(): void
{
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
}

/** @param list<array<array-key, string>|string|null> $commands */
function rootResolutionCount(array $commands): int
{
    return count(array_filter(
        $commands,
        static fn (array|string|null $command): bool => $command === ['git', 'rev-parse', '--show-toplevel'],
    ));
}
