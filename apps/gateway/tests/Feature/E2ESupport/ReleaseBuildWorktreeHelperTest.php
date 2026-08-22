<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('prepares and reuses an exact-commit release build worktree without touching the primary checkout', function (): void {
    $fixture = release_build_worktree_fixture();

    try {
        file_put_contents($fixture['mini'].'/.codex-config-user-change', "preserve\n");

        $first = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($first->getExitCode())
            ->toBe(0, $first->getOutput().$first->getErrorOutput())
            ->and($first->getOutput())
            ->toContain('RELEASE_BUILD_WORKTREE')
            ->and($fixture['mini'].'/.worktrees/release-native-'.substr($fixture['commit'], 0, 12))
            ->toBeDirectory()
            ->and(trim((string) file_get_contents($fixture['mini'].'/.codex-config-user-change')))
            ->toBe('preserve');

        $second = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($second->getExitCode())->toBe(0, $second->getErrorOutput());
    } finally {
        release_build_worktree_remove_fixture($fixture['root']);
    }
});

it('rejects a fetched branch whose tip differs from the requested commit', function (): void {
    $fixture = release_build_worktree_fixture();

    try {
        $wrongCommit = str_repeat('f', 40);
        $process = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$wrongCommit}",
        ]);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('fetched branch does not match requested commit')
            ->and($fixture['mini'].'/.worktrees/release-native-'.substr($wrongCommit, 0, 12))
            ->not->toBeDirectory()
            ->and($fixture['mini'].'/.worktrees/release-native-'.substr($fixture['commit'], 0, 12))
            ->not->toBeDirectory();
    } finally {
        release_build_worktree_remove_fixture($fixture['root']);
    }
});

it('preserves and rejects a dirty reused build worktree', function (): void {
    $fixture = release_build_worktree_fixture();

    try {
        $prepare = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($prepare->getExitCode())->toBe(0, $prepare->getOutput().$prepare->getErrorOutput());

        $worktree = $fixture['mini'].'/.worktrees/release-native-'.substr($fixture['commit'], 0, 12);
        file_put_contents($worktree.'/VERSION', "dirty-bytes\n");

        $reuse = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($reuse->getExitCode())
            ->toBe(1)
            ->and($reuse->getErrorOutput())
            ->toContain('build worktree is dirty')
            ->and((string) file_get_contents($worktree.'/VERSION'))
            ->toBe("dirty-bytes\n");
    } finally {
        release_build_worktree_remove_fixture($fixture['root']);
    }
});

it('refuses to remove a worktree with the wrong commit or dirty state', function (): void {
    $fixture = release_build_worktree_fixture();

    try {
        $prepare = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($prepare->getExitCode())->toBe(0, $prepare->getOutput().$prepare->getErrorOutput());

        $worktree = $fixture['mini'].'/.worktrees/release-native-'.substr($fixture['commit'], 0, 12);

        $wrong = release_build_worktree_process($fixture, [
            'remove',
            '--commit='.str_repeat('f', 40),
            "--path={$worktree}",
        ]);

        expect($wrong->getExitCode())
            ->toBe(1)
            ->and($worktree)
            ->toBeDirectory();

        file_put_contents($worktree.'/VERSION', "dirty-remove\n");

        $dirty = release_build_worktree_process($fixture, [
            'remove',
            "--commit={$fixture['commit']}",
        ]);

        expect($dirty->getExitCode())
            ->toBe(1)
            ->and($dirty->getErrorOutput())
            ->toContain('build worktree is dirty')
            ->and($worktree)
            ->toBeDirectory()
            ->and((string) file_get_contents($worktree.'/VERSION'))
            ->toBe("dirty-remove\n");
    } finally {
        release_build_worktree_remove_fixture($fixture['root']);
    }
});

it('removes only the clean exact build worktree', function (): void {
    $fixture = release_build_worktree_fixture();

    try {
        file_put_contents($fixture['mini'].'/.codex-config-user-change', "preserve\n");

        $prepare = release_build_worktree_process($fixture, [
            'prepare',
            "--source={$fixture['source']}",
            '--branch=main',
            "--commit={$fixture['commit']}",
        ]);

        expect($prepare->getExitCode())->toBe(0, $prepare->getOutput().$prepare->getErrorOutput());

        $worktree = $fixture['mini'].'/.worktrees/release-native-'.substr($fixture['commit'], 0, 12);

        $remove = release_build_worktree_process($fixture, [
            'remove',
            "--commit={$fixture['commit']}",
        ]);

        expect($remove->getExitCode())
            ->toBe(0, $remove->getOutput().$remove->getErrorOutput())
            ->and($remove->getOutput())
            ->toContain('RELEASE_BUILD_WORKTREE_REMOVED')
            ->and($worktree)
            ->not->toBeDirectory()
            ->and($fixture['mini'])
            ->toBeDirectory()
            ->and(trim((string) file_get_contents($fixture['mini'].'/.codex-config-user-change')))
            ->toBe('preserve');
    } finally {
        release_build_worktree_remove_fixture($fixture['root']);
    }
});

/**
 * @return array{root: string, source: string, mini: string, commit: string}
 */
function release_build_worktree_fixture(): array
{
    $root = sys_get_temp_dir().'/orbit-release-build-worktree-'.bin2hex(random_bytes(6));
    $source = $root.'/source';
    $mini = $root.'/mini';

    mkdir("{$source}/bin", recursive: true);
    mkdir("{$source}/apps/agent/src", recursive: true);
    mkdir("{$mini}/bin", recursive: true);

    file_put_contents("{$source}/VERSION", "0.1.200\n");
    file_put_contents("{$source}/bin/orbit-version", "#!/usr/bin/env bash\nprintf '0.1.200\\n'\n");
    file_put_contents("{$source}/apps/agent/src/main.rs", "fn main() {}\n");
    chmod("{$source}/bin/orbit-version", 0o755);

    release_build_worktree_git($source, ['init', '-b', 'main']);
    release_build_worktree_git($source, ['config', 'user.email', 'orbit@example.test']);
    release_build_worktree_git($source, ['config', 'user.name', 'Orbit Test']);
    release_build_worktree_git($source, ['add', 'VERSION', 'bin/orbit-version', 'apps/agent/src/main.rs']);
    release_build_worktree_git($source, ['commit', '-m', 'Source commit']);

    $commit = trim(release_build_worktree_git($source, ['rev-parse', 'HEAD']));

    $helperSource = repo_path('bin/orbit-release-build-worktree');

    if (! is_file($helperSource)) {
        throw new RuntimeException('bin/orbit-release-build-worktree does not exist.');
    }

    copy($helperSource, "{$mini}/bin/orbit-release-build-worktree");
    chmod("{$mini}/bin/orbit-release-build-worktree", 0o755);
    file_put_contents("{$mini}/.gitignore", "/.worktrees/\n");

    release_build_worktree_git($mini, ['init', '-b', 'main']);
    release_build_worktree_git($mini, ['config', 'user.email', 'orbit@example.test']);
    release_build_worktree_git($mini, ['config', 'user.name', 'Orbit Test']);
    release_build_worktree_git($mini, ['add', 'bin/orbit-release-build-worktree', '.gitignore']);
    release_build_worktree_git($mini, ['commit', '-m', 'Mini helper']);

    return [
        'root' => $root,
        'source' => $source,
        'mini' => $mini,
        'commit' => $commit,
    ];
}

function release_build_worktree_remove_fixture(string $root): void
{
    if ($root === '' || ! str_contains($root, '/orbit-release-build-worktree-')) {
        return;
    }

    new Process(['rm', '-rf', $root])->run();
}

/**
 * @param  list<string>  $arguments
 */
function release_build_worktree_process(array $fixture, array $arguments): Process
{
    $process = new Process(
        [$fixture['mini'].'/bin/orbit-release-build-worktree', ...$arguments],
        $fixture['mini'],
    );
    $process->run();

    return $process;
}

/**
 * @param  list<string>  $command
 */
function release_build_worktree_git(string $cwd, array $command): string
{
    $process = new Process(['git', ...$command], $cwd);
    $process->mustRun();

    return $process->getOutput();
}
