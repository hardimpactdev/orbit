<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('reads complete worktree listings without early-exit SIGPIPE', function () use ($read): void {
    foreach ([$read('bin/worktree-create'), $read('bin/worktree-remove')] as $script) {
        expect($script)->not->toContain("awk '/^worktree / { print substr(\$0, 10); exit }'");
        expect($script)->toContain("awk '/^worktree / && !found { print substr(\$0, 10); found=1 }'");
        expect($script)->not->toContain('grep -Fxq "worktree $worktree"');
        expect($script)->toContain('grep -Fx "worktree $worktree" >/dev/null');
    }
});

it('removes a worktree only after merge and never releases a topology', function () use ($read): void {
    $script = $read('bin/worktree-remove');

    expect($script)->not->toContain('e2e-topology');
    expect($script)->not->toContain('release');
    expect($script)->not->toContain('.e2e/attempt.json');

    expect($script)
        ->toContain('awk -v ref="branch refs/heads/$branch"')
        ->toContain('[[ -n "$worktree" ]] || worktree="$worktree_root/$name"');

    $mergeCheck = strpos($script, 'git merge-base --is-ancestor "$branch" origin/main');
    $remove = strpos($script, 'git worktree remove "$worktree"');

    expect($mergeCheck)
        ->not->toBeFalse()->and($remove)
        ->not->toBeFalse()->and($mergeCheck)->toBeLessThan($remove);
});

it('documents Node selection for extended issue topologies', function () use ($read): void {
    $script = $read('bin/e2e-topology');

    expect($script)
        ->toContain('acquire ISSUE WORKTREE [--nodes=LIST] [--replace]')
        ->toContain('declarable Nodes in apps/e2e/resources/topology-nodes.json');
});
