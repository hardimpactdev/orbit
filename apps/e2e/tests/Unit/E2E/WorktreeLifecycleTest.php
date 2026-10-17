<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root:string,run:ProcessFactory} */
function loopFlowFixture(): array
{
    $root = temporaryPath('orbit-flow-', 6);
    mkdir($root.'/bin', 0700, true);
    $run = new ProcessFactory;
    foreach ([
        ['init',   '-b',         'main'],
        ['config', 'user.name',  'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['config', 'orbit.worktreeRoot', $root.'-worktrees'],
    ] as $args) {
        expect($run->path($root)->run(['git', ...$args])->successful())->toBeTrue();
    }
    file_put_contents($root.'/.gitignore', "/.e2e/\n/.loop/\n/.worktrees/\n");
    file_put_contents($root.'/shared.txt', "base\n");
    foreach (['worktree-create', 'worktree-remove'] as $script) {
        copy(dirname(__DIR__, 5).'/bin/'.$script, $root.'/bin/'.$script);
        chmod($root.'/bin/'.$script, 0755);
    }
    file_put_contents($root.'/bin/bootstrap', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/bootstrap', 0755);
    file_put_contents(
        $root.'/bin/tia-cache',
        "#!/bin/sh\nprintf '%s\\n' \"\$*\" > \"\$(git rev-parse --git-common-dir)/tia-queued\"\nexit 1\n",
    );
    chmod($root.'/bin/tia-cache', 0755);
    expect($run->path($root)->run(['git', 'add', '.'])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'commit', '-m', 'base'])->successful())->toBeTrue();

    return ['root' => $root, 'run' => $run];
}

it('creates from fetched main without updating primary or queuing duplicate checks', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $primaryHead = $run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output();
    $remote = temporaryPath('orbit-flow-new-main-', 6);
    $run->run(['git', 'init', '--bare', $remote]);
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote]);
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main']);
    $source = $root.'/.worktrees/advance-main';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'advance-main', $source]);
    file_put_contents($source.'/next.txt', 'new main');
    $run->path($source)->run(['git', 'add', '.']);
    $run->path($source)->run(['git', 'commit', '-m', 'advance main']);
    $run->path($source)->run(['git', 'push', 'origin', 'HEAD:main']);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-46']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(file_exists($root.'/next.txt'))->toBeFalse();
    expect($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output())->toBe($primaryHead);
    expect(file_get_contents($root.'-worktrees/tst-46/next.txt'))->toBe('new main');
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
    expect($run->path($root)->run(['git', 'status', '--porcelain'])->output())->toBe('');
});

it('creates from main while preserving a dirty primary on another branch', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root]);
    $run->path($root)->run(['git', 'checkout', '-b', 'running-checkout']);
    $primaryHead = $run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output();
    file_put_contents($root.'/shared.txt', 'active migration');
    $run->path($root)->run(['git', 'add', 'shared.txt']);
    $index = $run->path($root)->run(['git', 'write-tree'])->output();
    file_put_contents($root.'/untracked.txt', 'unrelated work');

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-47']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(file_get_contents($root.'/shared.txt'))->toBe('active migration');
    expect(file_get_contents($root.'/untracked.txt'))->toBe('unrelated work');
    expect($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output())->toBe($primaryHead);
    expect($run->path($root)->run(['git', 'write-tree'])->output())->toBe($index);
    expect(trim($run->path($root)->run(['git', 'branch', '--show-current'])->output()))->toBe('running-checkout');
    expect(file_get_contents($root.'-worktrees/tst-47/shared.txt'))->toBe("base\n");
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});

it('refuses a configured worktree root inside primary or a relative path', function (string $suffix): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $path = $suffix === 'relative' ? 'relative/worktrees' : $root.$suffix;
    $run->path($root)->run(['git', 'config', 'orbit.worktreeRoot', $path]);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-48']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('orbit.worktreeRoot must be');
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
})->with(['', '/nested', 'relative']);

it('reuses an existing registered branch after its worktree moves', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root]);
    $run->path($root)->run(['git', 'fetch', 'origin']);
    $moved = $root.'-moved worktree';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-49-existing', $moved]);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-49']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(trim($result->output()))->toEndWith($moved);
    expect(is_dir($moved.'/.loop'))->toBeFalse();
    expect(is_dir($root.'-worktrees/tst-49'))->toBeFalse();
});

it('refuses ambiguous legacy branches for issue-only creation and cleanup', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'branch', 'tst-50-first']);
    $run->path($root)->run(['git', 'branch', 'tst-50-second']);
    foreach (['worktree-create', 'worktree-remove'] as $script) {
        $result = $run->path($root)->run([$root.'/bin/'.$script, 'TST-50']);
        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('More than one branch matches TST-50');
    }
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});

it('closes out a merged worktree when the repository has a long worktree listing', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-cleanup-remote-', 6);
    expect($run->run(['git', 'init', '--bare', $remote])->successful())->toBeTrue();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote]);
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main']);
    $worktree = $root.'/.worktrees/tst-44-cleanup';
    expect(
        $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-44-cleanup', $worktree])->successful(),
    )->toBeTrue();
    $wrapper = temporaryPath('orbit-flow-git-wrapper-', 6);
    mkdir($wrapper);
    $git = trim($run->run(['which', 'git'])->output());
    file_put_contents(
        $wrapper.'/git',
        "#!/usr/bin/env python3\n"
        .'import subprocess,sys'
        ."\n"
        .'args=sys.argv[1:]'
        ."\n"
        .'real='
        .json_encode($git, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ."\n"
        .'result=subprocess.run([real,*args],stdout=subprocess.PIPE)'
        ."\n"
        .'sys.stdout.buffer.write(result.stdout)'
        ."\n"
        .'if args == ["worktree","list","--porcelain"]:'
        ."\n"
        .'    sys.stdout.write("".join(f"worktree /tmp/listing-{i}\\nHEAD {i:040x}\\nbranch refs/heads/listing-{i}\\n\\n" for i in range(4000)))'
        ."\n"
        .'sys.exit(result.returncode)'
        ."\n",
    );
    chmod($wrapper.'/git', 0755);

    $result = $run->path($root)->env(['PATH' => $wrapper.':'.getenv('PATH')])->run([
        $root.'/bin/worktree-remove',
        'TST-44',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(trim(file_get_contents($root.'/.git/tia-queued')))->toBe('refresh --background --repository='.$root);
    expect(is_dir($worktree))->toBeFalse();
    expect(
        $run->path($root)->run(['git', 'show-ref', '--verify', 'refs/heads/tst-44-cleanup'])->successful(),
    )->toBeFalse();
});

it('removes a merged worktree with a topology attempt without calling the topology tool', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-attempt-remote-', 6);
    $run->run(['git', 'init', '--bare', $remote])->throw();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote])->throw();
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main'])->throw();
    $worktree = $root.'-worktrees/tst-48';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-48', $worktree])->throw();
    mkdir($worktree.'/.e2e', 0700, true);
    file_put_contents($worktree.'/.e2e/attempt.json', '{}');
    file_put_contents(
        $root.'/bin/e2e-topology',
        "#!/bin/sh\nprintf '%s\\n' \"\$*\" > \"\$(git rev-parse --git-common-dir)/topology-called\"\n",
    );
    chmod($root.'/bin/e2e-topology', 0755);

    $result = $run->path($root)->run([$root.'/bin/worktree-remove', 'TST-48']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(file_exists($root.'/.git/topology-called'))
        ->toBeFalse()
        ->and(is_dir($worktree))
        ->toBeFalse();
});

it('does not queue cache refresh or remove an unmerged feature', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root]);
    $run->path($root)->run(['git', 'fetch', 'origin']);
    $worktree = $root.'/.worktrees/tst-45-unmerged';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-45-unmerged', $worktree]);
    file_put_contents($worktree.'/feature.txt', 'unmerged feature');
    $run->path($worktree)->run(['git', 'add', '.']);
    $run->path($worktree)->run(['git', 'commit', '-m', 'feature']);

    $result = $run->path($root)->run([$root.'/bin/worktree-remove', 'TST-45']);

    expect($result->successful())->toBeFalse();
    expect(is_dir($worktree))->toBeTrue();
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});
