<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 5).'/bin/orbit-finalization-tmux-land.php';

/**
 * LAND coordinator and tmux kill-session grammar. Fixture Git + real tmux on a
 * private socket; skip when tmux is not on PATH.
 */

it('blocks direct cleanup when the matching archive and index are present but uncommitted', function (): void {
    $fixture = land_create_fixture();

    try {
        land_commit_feature_change($fixture['worktree']);
        land_write_accepted_loop($fixture['repo'], $fixture['worktree']);
        land_merge_feature($fixture['repo']);
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));

        $process = land_run_finalization($fixture, "git worktree remove {$fixture['worktree']}");

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->toMatch('/tracked|committed|index\.json/i');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('exposes one-step resume after merge and keeps archive-commit as the next ordered phase', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $status = land_run_land($fixture, land_args($fixture, ['--status']));

        expect($status->getExitCode())
            ->toBe(0, $status->getErrorOutput().$status->getOutput())
            ->and(strtolower($status->getOutput().$status->getErrorOutput()))
            ->toMatch('/phase[=:\s]+archive|next[_\s-]?action[=:\s]+archive/');

        $plan = land_run_land($fixture, land_args($fixture, ['--plan']));
        expect($plan->getExitCode())
            ->toBe(0, $plan->getErrorOutput().$plan->getOutput())
            ->and($plan->getOutput())
            ->toContain('phase=archive');

        $oneStep = land_run_land($fixture, land_args($fixture, ['--one-step']));

        expect($oneStep->getExitCode())
            ->toBe(0, $oneStep->getErrorOutput().$oneStep->getOutput());

        $archives = glob("{$fixture['repo']}/.orbit/sessions/*-feature") ?: [];
        expect($archives)->not->toBeEmpty('one-step after merge must construct the session archive');

        $afterArchive = land_run_land($fixture, land_args($fixture, ['--status']));

        expect($afterArchive->getExitCode())
            ->toBe(0, $afterArchive->getErrorOutput().$afterArchive->getOutput())
            ->and(strtolower($afterArchive->getOutput().$afterArchive->getErrorOutput()))
            ->toMatch('/archive-commit|commit.*archive|index\.json/')
            ->and(
                trim(
                    new Process(['git', 'status', '--porcelain', '--', '.orbit/sessions'], $fixture['repo'])
                        ->mustRun()
                        ->getOutput(),
                ),
            )
            ->not->toBe('');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('completes a clean full land and is idempotent at done', function (): void {
    $fixture = land_prepare(accepted: true, merged: false);
    $otherWorktree = land_add_unrelated_worktree($fixture);

    try {
        $full = land_run_land($fixture, land_args($fixture));

        expect($full->getExitCode())
            ->toBe(0, $full->getErrorOutput().$full->getOutput())
            ->and($full->getOutput())
            ->toContain('phase=done')
            ->and(is_dir($fixture['worktree']))
            ->toBeFalse()
            ->and(land_branch_exists($fixture['repo'], 'feature'))
            ->toBeFalse()
            ->and(is_dir($otherWorktree))
            ->toBeTrue()
            ->and(land_branch_exists($fixture['repo'], 'unrelated'))
            ->toBeTrue();

        $headBefore = trim(new Process(['git', 'rev-parse', 'HEAD'], $fixture['repo'])->mustRun()->getOutput());
        $again = land_run_land($fixture, land_args($fixture));
        $again2 = land_run_land($fixture, land_args($fixture));
        $headAfter = trim(new Process(['git', 'rev-parse', 'HEAD'], $fixture['repo'])->mustRun()->getOutput());

        expect($again->getExitCode())
            ->toBe(0, $again->getErrorOutput().$again->getOutput())
            ->and($again->getOutput())
            ->toContain('phase=done')
            ->and($again2->getExitCode())
            ->toBe(0)
            ->and($again2->getOutput())
            ->toContain('phase=done')
            ->and($headAfter)
            ->toBe($headBefore);
    } finally {
        land_remove_fixture($fixture);
        if (is_dir($otherWorktree)) {
            new Process(['rm', '-rf', $otherWorktree])->run();
        }
    }
});

it('completes LAND when the exact candidate contract lowers the main-derived venue', function (): void {
    $fixture = land_create_fixture();

    try {
        file_put_contents(
            "{$fixture['worktree']}/bin/orbit-loop-contract.php",
            <<<'PHP'
                <?php

                declare(strict_types=1);

                function orbitLoopAcceptanceVenue(array $changedFiles): string
                {
                    return 'automated';
                }
                PHP,
        );
        file_put_contents("{$fixture['worktree']}/apps/cli/runtime.php", "<?php\n\n// Candidate-routed runtime.\n");
        land_run($fixture['worktree'], ['git', 'add', 'bin/orbit-loop-contract.php', 'apps/cli/runtime.php']);
        land_run($fixture['worktree'], ['git', 'commit', '-m', 'Change candidate acceptance routing']);
        land_write_accepted_loop($fixture['repo'], $fixture['worktree'], 'quality-check');

        $full = land_run_land($fixture, land_args($fixture));

        expect($full->getExitCode())
            ->toBe(0, $full->getErrorOutput().$full->getOutput())
            ->and($full->getOutput())
            ->toContain('phase=done')
            ->and(is_dir($fixture['worktree']))
            ->toBeFalse()
            ->and(land_branch_exists($fixture['repo'], 'feature'))
            ->toBeFalse();
    } finally {
        land_remove_fixture($fixture);
    }
});

it('refuses dirty feature worktrees and unmerged identity for cleanup mutations', function (): void {
    $fixture = land_prepare(accepted: true, merged: false);

    try {
        file_put_contents("{$fixture['worktree']}/DIRTY.txt", "dirt\n");

        $dirty = land_run_land($fixture, land_args($fixture, ['--one-step']));

        expect($dirty->getExitCode())
            ->toBe(1, $dirty->getErrorOutput().$dirty->getOutput())
            ->and(strtolower($dirty->getErrorOutput().$dirty->getOutput()))
            ->toMatch('/dirty|uncommitted|finalization blocked/');

        unlink("{$fixture['worktree']}/DIRTY.txt");
        land_merge_feature($fixture['repo']);
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        $cleanup = land_run_finalization($fixture, "git worktree remove {$fixture['worktree']}");

        expect($cleanup->getExitCode())
            ->toBe(2)
            ->and($cleanup->getErrorOutput())
            ->toMatch('/tracked|committed|index\.json/i');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('refuses self-cwd cleanup and landing from inside the target session', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $self = land_run_land($fixture, land_args($fixture, ['--status']), cwd: $fixture['worktree']);

        expect($self->getExitCode())
            ->toBe(1)
            ->and(strtolower($self->getErrorOutput()))
            ->toMatch('/self-cleanup|inside/');

        $socketPath = land_tmux_socket_path($fixture);
        $inside = land_run_land(
            $fixture,
            land_args($fixture, ['--status']),
            ['TMUX' => "{$socketPath},0,0"],
        );

        expect($inside->getExitCode())
            ->toBe(1)
            ->and(strtolower($inside->getErrorOutput()))
            ->toMatch('/inside the feature session|orchestrator session|outside tmux/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('teaches on missing, invalid, and mismatched Session lines', function (
    string $sessionLine,
    string $needle,
): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $loop = (string) file_get_contents("{$fixture['worktree']}/.orbit/loop.md");

        if ($sessionLine === '') {
            $loop = preg_replace('/^- Session:.*\n/m', '', $loop) ?? $loop;
        } else {
            $loop = preg_replace('/^- Session:.*$/m', $sessionLine, $loop, limit: 1) ?? $loop;
        }

        file_put_contents("{$fixture['worktree']}/.orbit/loop.md", $loop);

        $status = land_run_land($fixture, land_args($fixture, ['--status']));

        expect($status->getExitCode())
            ->toBe(1)
            ->and(strtolower($status->getErrorOutput().$status->getOutput()))
            ->toMatch($needle);
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'missing' => ['', '/missing - session:/'],
    'invalid charset' => ['- Session: feat_Feature', '/invalid - session:|feat-<slug>/'],
    'basename mismatch' => ['- Session: feat-other', '/does not match worktree basename|expected `feat-feature`/'],
]);

it('refuses a live session whose path does not equal the feature worktree', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        new Process([
            'tmux',
            '-L',
            $fixture['socket'],
            'kill-session',
            '-t',
            '='.$fixture['session'],
        ])->mustRun();
        land_tmux_new_session($fixture['socket'], $fixture['session'], $fixture['repo']);

        $status = land_run_land($fixture, land_args($fixture, ['--status']));

        expect($status->getExitCode())
            ->toBe(1)
            ->and(strtolower($status->getErrorOutput()))
            ->toMatch('/does not equal feature worktree|equals the primary checkout/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('treats a missing session as idempotent and resumes at remove-worktree', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        new Process([
            'tmux',
            '-L',
            $fixture['socket'],
            'kill-session',
            '-t',
            '='.$fixture['session'],
        ])->mustRun();
        land_tmux_new_session($fixture['socket'], name: 'feat-keep-server', cwd: $fixture['repo']);

        $status = land_run_land($fixture, land_args($fixture, ['--status']));

        expect($status->getExitCode())
            ->toBe(0, $status->getErrorOutput().$status->getOutput())
            ->and($status->getOutput())
            ->toContain('phase=remove-worktree');

        $headBefore = trim(new Process(['git', 'rev-parse', 'HEAD'], $fixture['repo'])->mustRun()->getOutput());
        $resume = land_run_land($fixture, land_args($fixture));
        expect($resume->getExitCode())
            ->toBe(0, $resume->getErrorOutput().$resume->getOutput())
            ->and($resume->getOutput())
            ->toContain('phase=done')
            ->and(is_dir($fixture['worktree']))
            ->toBeFalse()
            ->and(land_branch_exists($fixture['repo'], 'feature'))
            ->toBeFalse();

        $again = land_run_land($fixture, land_args($fixture));
        $headAfter = trim(new Process(['git', 'rev-parse', 'HEAD'], $fixture['repo'])->mustRun()->getOutput());
        expect($again->getExitCode())
            ->toBe(0, $again->getErrorOutput().$again->getOutput())
            ->and($again->getOutput())
            ->toContain('phase=done')
            ->and($headAfter)
            ->toBe($headBefore);
    } finally {
        land_remove_fixture($fixture);
    }
});

it('fail-closes unreachable tmux errors and treats a missing session as not_found', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        new Process([
            'tmux',
            '-L',
            $fixture['socket'],
            'kill-session',
            '-t',
            '='.$fixture['session'],
        ])->mustRun();
        land_tmux_new_session($fixture['socket'], name: 'feat-keep-server', cwd: $fixture['repo']);

        $missing = land_run_land($fixture, land_args($fixture, ['--status']));
        expect($missing->getExitCode())
            ->toBe(0, $missing->getErrorOutput().$missing->getOutput())
            ->and($missing->getOutput())
            ->toContain('phase=remove-worktree');

        $fakeDir = land_write_fake_tmux($fixture['root'], mode: 'error');
        $broken = land_run_land(
            $fixture,
            land_args($fixture, ['--status']),
            ['PATH' => $fakeDir.':'.getenv('PATH')],
        );

        expect($broken->getExitCode())
            ->toBe(1)
            ->and(strtolower($broken->getErrorOutput().$broken->getOutput()))
            ->toMatch('/tmux lookup failed|permission denied|error connecting/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('one-step advances each LAND boundary from a seeded interrupted state', function (
    string $seed,
    string $expectedPhaseAfter,
    string $expectedNextNeedle,
): void {
    $fixture = land_prepare(accepted: true, merged: false);

    try {
        land_seed_boundary($fixture, $seed);

        $before = land_run_land($fixture, land_args($fixture, ['--status']));
        expect($before->getExitCode())->toBe(0, $before->getErrorOutput().$before->getOutput());

        $step = land_run_land($fixture, land_args($fixture, ['--one-step']));
        expect($step->getExitCode())
            ->toBe(0, $step->getErrorOutput().$step->getOutput())
            ->and($step->getOutput())
            ->toContain("phase={$expectedPhaseAfter}")
            ->and(strtolower($step->getOutput()))
            ->toMatch($expectedNextNeedle);
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'after merge' => ['after-merge', 'archive-commit', '/archive-commit|commit archive/'],
    'after archive construction' => ['after-archive', 'kill-session', '/kill-session/'],
    'after archive commit' => ['after-archive-commit', 'remove-worktree', '/worktree remove|remove-worktree/'],
    'after kill-session' => ['after-kill-session', 'delete-branch', '/branch -d|delete-branch/'],
    'after worktree removal' => ['after-worktree-removed', 'done', '/next_action=none|phase=done/'],
    'after branch deletion' => ['after-branch-deleted', 'done', '/next_action=none|phase=done/'],
]);

it('strictly classifies tmux kill-session commands', function (string $command, string $needle): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $process = land_run_finalization($fixture, $command);

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch($needle);
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'extra operands' => ['tmux kill-session -t =feat-feature extra', '/extra operands|invalid|blocked/'],
    'unknown flag' => ['tmux kill-session -t =feat-feature --force', '/unknown option|blocked/'],
    'missing equals' => ['tmux kill-session -t feat-feature', '/= prefix|exact-match|=feat-|invalid|blocked/'],
    'chained commands' => [
        'tmux kill-session -t =feat-feature && tmux kill-session -t =feat-other',
        '/unchained|exactly one|blocked/',
    ],
    'equals plus quoted -L' => [
        "tmux -L='evil' kill-session -t =feat-feature",
        '/quoted|shell-fragment|invalid|blocked/',
    ],
    'quoted -S path' => [
        'tmux -S="/tmp/x" kill-session -t =feat-feature',
        '/quoted|shell-fragment|invalid|blocked/',
    ],
    'backslash socket' => [
        'tmux -L foo\\bar kill-session -t =feat-feature',
        '/quoted|shell-fragment|invalid|blocked/',
    ],
    'kill-server' => ['tmux kill-server', '/kill-server|not an allowed|invalid|blocked/'],
    'kill-server with -L' => ['tmux -L sock kill-server', '/kill-server|not an allowed|invalid|blocked/'],
    'kill-window' => [
        'tmux kill-window -t =feat-x:impl-1',
        '/kill-window|not an allowed|invalid|blocked/',
    ],
    'kill-pane' => ['tmux kill-pane', '/kill-pane|not an allowed|invalid|blocked/'],
    'killw alias' => [
        'tmux killw -t =feat-x:impl-1',
        '/killw|not an allowed|invalid|blocked/',
    ],
    'killp alias' => [
        'tmux killp -t =feat-x:impl-1',
        '/killp|not an allowed|invalid|blocked/',
    ],
    'killw alias with -L' => [
        'tmux -L sock killw -t =feat-x:impl-1',
        '/killw|not an allowed|invalid|blocked/',
    ],
]);

it('accepts -L and -S socket forms for an owned kill-session', function (string $flagTemplate): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $socketPath = land_tmux_socket_path($fixture);
        $ambientSocket = 'orbit-land-ambient-'.bin2hex(random_bytes(4));
        $command = str_replace(
            ['{socket}', '{socket_path}'],
            [$fixture['socket'], $socketPath],
            $flagTemplate,
        );
        $process = land_run_finalization($fixture, $command, [
            'ORBIT_TMUX_SOCKET' => $ambientSocket,
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS')
            ->and($process->getOutput())
            ->toContain('tmux kill-session feat-feature');
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'separate -L' => ['tmux -L {socket} kill-session -t =feat-feature'],
    'glued -L' => ['tmux -L{socket} kill-session -t =feat-feature'],
    'separate -S' => ['tmux -S {socket_path} kill-session -t =feat-feature'],
    'glued -S' => ['tmux -S{socket_path} kill-session -t =feat-feature'],
    'quoted target' => ["tmux -L {socket} kill-session -t '=feat-feature'"],
]);

it('blocks kill-session when the command socket is a foreign unlinked path', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);
    $foreignRoot = sys_get_temp_dir().'/orbit-land-foreign-'.bin2hex(random_bytes(6));
    $foreignSocket = 'orbit-land-foreign-'.bin2hex(random_bytes(6));

    try {
        land_mkdir($foreignRoot);
        land_tmux_new_session($foreignSocket, name: 'feat-x', cwd: $foreignRoot);

        $process = land_run_finalization(
            $fixture,
            "tmux -L {$foreignSocket} kill-session -t =feat-x",
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch('/linked feature worktree|canonical project path|ownership|blocked/')
            ->and($process->getOutput())
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_tmux_kill_server($foreignSocket);
        new Process(['rm', '-rf', $foreignRoot])->run();
        land_remove_fixture($fixture);
    }
});

it('looks up an owned session through the command -L even when ORBIT_TMUX_SOCKET differs', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $ambientSocket = 'orbit-land-ambient-'.bin2hex(random_bytes(4));
        $process = land_run_finalization(
            $fixture,
            "tmux -L {$fixture['socket']} kill-session -t =feat-feature",
            ['ORBIT_TMUX_SOCKET' => $ambientSocket],
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS')
            ->and($process->getOutput())
            ->toContain('tmux kill-session feat-feature');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('does not treat a documented kill-session line inside a heredoc as a LAND boundary', function (): void {
    $fixture = land_create_fixture();

    try {
        $command = <<<'BASH'
            cat > notes.md <<'EOF'
            tmux kill-session -t '=feat-x'
            EOF
            BASH;

        $hook = land_run_hook($fixture, $command, explicit: false);

        expect($hook->getExitCode())
            ->toBe(0, $hook->getErrorOutput().$hook->getOutput())
            ->and($hook->getErrorOutput().$hook->getOutput())
            ->not->toContain('FINALIZATION: BLOCKED')
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('still classifies an exact kill-session whose command line opens a heredoc', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $command = "tmux -L {$fixture['socket']} kill-session -t =feat-feature <<'EOF'\nbody\nEOF";
        $process = land_run_finalization($fixture, $command);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS')
            ->and($process->getOutput())
            ->toContain('tmux kill-session feat-feature');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('does not treat a quoted << as a heredoc opener that hides a following kill-session', function (): void {
    $fixture = land_create_fixture();

    try {
        $process = land_run_finalization(
            $fixture,
            "echo \"a << b\"\ntmux kill-session -t '=feat-x'",
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch('/unchained|exactly one|blocked|kill-session/')
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('does not silent-pass a quoted << search chained with kill-session', function (): void {
    $fixture = land_create_fixture();

    try {
        $process = land_run_finalization(
            $fixture,
            "rg -n '<<' bin/ && tmux kill-session -t '=feat-x'",
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch('/unchained|exactly one|blocked|kill-session/')
            ->and($process->getOutput())
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('does not excise an unterminated heredoc so the kill-session line stays classified', function (): void {
    $fixture = land_create_fixture();

    try {
        $process = land_run_finalization(
            $fixture,
            "cat <<'EOF'\ntmux kill-session -t '=feat-x'",
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch('/unchained|exactly one|blocked|kill-session/')
            ->and($process->getOutput())
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('still counts merge plus kill-session when a quoted << precedes both', function (): void {
    $fixture = land_create_fixture();

    try {
        $process = land_run_finalization(
            $fixture,
            "echo \"a << b\"\ngit merge main\ntmux kill-session -t '=feat-x'",
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('exactly one destructive boundary action is allowed; found 2')
            ->and($process->getOutput())
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('still rejects two real kill-session lines outside a heredoc as chained', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $command = "tmux kill-session -t =feat-feature\ntmux kill-session -t =feat-other";
        $process = land_run_finalization($fixture, $command);

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch('/unchained|exactly one|blocked/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('silent-passes quoted tmux mentions and non-destructive tmux subcommands in hook mode', function (
    string $command,
): void {
    $fixture = land_create_fixture();

    try {
        $hook = land_run_hook($fixture, $command, explicit: false);

        expect($hook->getExitCode())
            ->toBe(0, $hook->getErrorOutput().$hook->getOutput())
            ->and($hook->getErrorOutput().$hook->getOutput())
            ->not->toContain('FINALIZATION: BLOCKED')
            ->not->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'quoted tmux in rg chain' => ['rg "tmux kill-session" README.md && true'],
    'ls' => ['tmux ls'],
    'attach' => ['tmux attach -t =feat-feature'],
    'new-session' => ['tmux new-session -d -s feat-other'],
    'send-keys' => ['tmux send-keys -t =feat-feature hello'],
    'capture-pane' => ['tmux capture-pane -t =feat-feature'],
]);

it('explicit wrapper fails loud for unclassifiable tmux commands but blocks malformed kill-session', function (
    string $command,
    int $exit,
    string $needle,
): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $process = land_run_finalization($fixture, $command);

        expect($process->getExitCode())
            ->toBe($exit, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch($needle);
    } finally {
        land_remove_fixture($fixture);
    }
})->with([
    'explicit list unclassifiable' => [
        'tmux ls',
        64,
        '/could not classify|unclassifiable|orbit finalization gate/',
    ],
    'malformed kill-session' => [
        'tmux kill-session',
        2,
        '/= prefix|exact-match|invalid|blocked/',
    ],
]);

it('rejects leftover --solo-* options', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $process = land_run_land($fixture, [
            '--branch=feature',
            "--worktree={$fixture['worktree']}",
            '--solo-project-id=73',
            '--status',
        ]);

        expect($process->getExitCode())
            ->toBe(1)
            ->and(strtolower($process->getErrorOutput()))
            ->toMatch('/invalid option/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('refuses remove-worktree when the path is linked to a different branch than --branch', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);
    $other = land_add_unrelated_worktree($fixture);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $status = land_run_land($fixture, [
            '--branch=feature',
            "--worktree={$other}",
            '--status',
        ]);

        expect($status->getExitCode())
            ->toBe(1, $status->getErrorOutput().$status->getOutput())
            ->and(strtolower($status->getErrorOutput()))
            ->toMatch('/linked to|not .*feature|worktree/');
    } finally {
        land_remove_fixture($fixture);
        if (is_dir($other)) {
            new Process(['rm', '-rf', $other])->run();
        }
    }
});

it('aborts full execute when a mutation leaves phase and next_action unchanged', function (): void {
    $fixture = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $fakeDir = land_write_fake_tmux($fixture['root'], mode: 'no-progress', sessionPath: $fixture['worktree']);
        $full = land_run_land(
            $fixture,
            land_args($fixture),
            ['PATH' => $fakeDir.':'.getenv('PATH')],
        );

        expect($full->getExitCode())
            ->toBe(1, $full->getErrorOutput().$full->getOutput())
            ->and(strtolower($full->getErrorOutput().$full->getOutput()))
            ->toMatch('/no progress|unchanged/');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('shares archive slug fallback session not feature for punctuation-only names', function (): void {
    expect(orbit_land_archive_slug('@@@'))
        ->toBe('session')
        ->and(orbit_land_archive_slug('My Feature'))
        ->toBe('my-feature');
});

it('allows legacy full archive cleanup when loop and agent manifests are tracked and clean', function (): void {
    $fixture = land_create_fixture();

    try {
        land_commit_feature_change($fixture['worktree']);
        land_write_accepted_loop($fixture['repo'], $fixture['worktree']);
        land_merge_feature($fixture['repo']);

        $archive = "{$fixture['repo']}/.orbit/sessions/2026-08-05-120000-feature";
        land_mkdir("{$archive}/agent-sessions");
        copy("{$fixture['worktree']}/.orbit/loop.md", "{$archive}/loop.md");
        file_put_contents(
            "{$archive}/agent-sessions/manifest.json",
            json_encode(['schema_version' => 1, 'sessions' => []], JSON_THROW_ON_ERROR).PHP_EOL,
        );
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);

        $process = land_run_finalization($fixture, "git worktree remove {$fixture['worktree']}");

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        land_remove_fixture($fixture);
    }
});

it('blocks cleanup when a compact receipt is tracked but a required loop entry is untracked', function (): void {
    $fixture = land_create_fixture();

    try {
        land_commit_feature_change($fixture['worktree']);
        land_write_accepted_loop($fixture['repo'], $fixture['worktree']);
        land_merge_feature($fixture['repo']);
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));

        $relative = ltrim(str_replace($fixture['repo'], '', $archive), '/');
        land_run($fixture['repo'], [
            'git',
            'add',
            '--',
            "{$relative}/orbit-session-archive.json",
            '.orbit/sessions/index.json',
        ]);
        land_run($fixture['repo'], ['git', 'commit', '-m', 'Track receipt only']);

        $process = land_run_finalization($fixture, "git worktree remove {$fixture['worktree']}");

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->toMatch('/uncommitted|untracked|loop\.md|required archive entry/i');
    } finally {
        land_remove_fixture($fixture);
    }
});

/**
 * @return array{root: string, repo: string, worktree: string, socket: string, session: string}
 */
function land_prepare(bool $accepted, bool $merged): array
{
    $fixture = land_create_fixture();

    if ($accepted) {
        land_commit_feature_change($fixture['worktree']);
        land_write_accepted_loop($fixture['repo'], $fixture['worktree']);
    } else {
        land_write_session_header($fixture['worktree']);
    }

    if ($merged) {
        land_merge_feature($fixture['repo']);
    }

    return $fixture;
}

/**
 * @param  array{worktree: string}  $fixture
 * @param  list<string>  $extra
 * @return list<string>
 */
function land_args(array $fixture, array $extra = []): array
{
    return [
        '--branch=feature',
        "--worktree={$fixture['worktree']}",
        ...$extra,
    ];
}

/**
 * @param  array{root: string, repo: string}  $fixture
 */
function land_add_unrelated_worktree(array $fixture): string
{
    $path = $fixture['root'].'/unrelated';
    land_run($fixture['repo'], ['git', 'branch', 'unrelated']);
    land_run($fixture['repo'], ['git', 'worktree', 'add', $path, 'unrelated']);
    land_write_session_header($path, session: 'feat-unrelated');

    return $path;
}

function land_branch_exists(string $repo, string $branch): bool
{
    return new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"], $repo)->run() === 0;
}

/**
 * @return array{root: string, repo: string, worktree: string, socket: string, session: string}
 */
function land_create_fixture(bool $withSession = true): array
{
    land_require_tmux();

    $root = sys_get_temp_dir().'/orbit-feature-land-'.bin2hex(random_bytes(6));
    $repo = $root.'/primary';
    $worktree = $root.'/feature';
    $socket = 'orbit-land-'.bin2hex(random_bytes(6));
    $session = 'feat-feature';

    land_mkdir($repo);
    land_run($repo, ['git', 'init']);
    land_run($repo, ['git', 'checkout', '-b', 'main']);
    land_run($repo, ['git', 'config', 'user.email', 'orbit@example.test']);
    land_run($repo, ['git', 'config', 'user.name', 'Orbit Test']);

    file_put_contents("{$repo}/HARNESS.md", "# Harness\n");
    file_put_contents("{$repo}/AGENTS.md", "# Agents\n");
    file_put_contents(
        "{$repo}/.gitignore",
        "/.orbit/*\n!/.orbit/sessions/\n!/.orbit/sessions/**\n",
    );
    land_mkdir("{$repo}/apps/cli");
    land_mkdir("{$repo}/bin");
    file_put_contents("{$repo}/apps/cli/runtime.php", "<?php\n");
    copy(repo_path('bin/orbit-loop-contract.php'), "{$repo}/bin/orbit-loop-contract.php");
    copy(repo_path('bin/orbit-quality-subgates.php'), "{$repo}/bin/orbit-quality-subgates.php");
    copy(repo_path('bin/quality-check.sh'), "{$repo}/bin/quality-check.sh");

    land_run($repo, ['git', 'add', 'HARNESS.md', 'AGENTS.md', '.gitignore', 'apps', 'bin']);
    land_run($repo, ['git', 'commit', '-m', 'Initial commit']);
    land_run($repo, ['git', 'branch', 'feature']);
    land_run($repo, ['git', 'worktree', 'add', $worktree, 'feature']);
    land_write_session_header($worktree, $session);

    if ($withSession) {
        land_tmux_new_session($socket, $session, $worktree);
    }

    return [
        'root' => $root,
        'repo' => $repo,
        'worktree' => $worktree,
        'socket' => $socket,
        'session' => $session,
    ];
}

function land_write_session_header(string $worktree, ?string $session = null): void
{
    $resolvedWorktree = realpath($worktree);
    $session ??= 'feat-'.basename($resolvedWorktree === false ? $worktree : $resolvedWorktree);
    land_mkdir($worktree.'/.orbit');
    $path = $worktree.'/.orbit/loop.md';
    $existing = is_file($path) ? (string) file_get_contents($path) : '';

    if ($existing === '') {
        file_put_contents($path, <<<MARKDOWN
            # Orbit Feature Loop

            - Session: {$session}
            - Worktree: {$worktree}
            - Branch: feature

            MARKDOWN);

        return;
    }

    if (preg_match('/^- Session:/m', $existing) === 1) {
        $existing = (string) preg_replace('/^- Session:.*$/m', "- Session: {$session}", $existing, limit: 1);
    } else {
        $existing = "- Session: {$session}\n".$existing;
    }

    file_put_contents($path, $existing);
}

function land_commit_feature_change(string $worktree): void
{
    file_put_contents("{$worktree}/HARNESS.md", "# Compact harness\n");
    land_run($worktree, ['git', 'add', 'HARNESS.md']);
    land_run($worktree, ['git', 'commit', '-m', 'Feature change']);
}

function land_write_accepted_loop(string $repo, string $worktree, string $gate = 'docs-lint'): void
{
    $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
    $resolvedWorktree = realpath($worktree);
    $session = 'feat-'.basename($resolvedWorktree === false ? $worktree : $resolvedWorktree);

    $artifactDir = "{$worktree}/.orbit/quality-gates";
    land_mkdir($artifactDir);
    $endedAt = '2026-08-05T12:00:00+00:00';
    $payload = [
        'gate' => $gate,
        'producer' => $gate === 'quality-check' ? 'quality-check.sh' : 'quality-gate-run',
        'command' => "composer {$gate}",
        'mode' => 'check',
        'exit_code' => 0,
        'duration_ms' => 10,
        'started_at' => '2026-08-05T11:59:00+00:00',
        'ended_at' => $endedAt,
        'git' => [
            'branch' => 'feature',
            'commit' => $featureTip,
            'dirty' => false,
        ],
        'subgates' => $gate === 'quality-check' ? land_quality_check_subgates() : [],
    ];
    file_put_contents(
        "{$artifactDir}/{$gate}-2026-08-05T120000Z.json",
        json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    file_put_contents("{$worktree}/.orbit/loop.md", <<<MARKDOWN
        # Orbit Feature Loop

        - Session: {$session}
        - Worktree: {$worktree}
        - Branch: feature

        ## Goal

        Land atomic saga fixture.

        ## Scope

        - Owned: bin/orbit-feature-land; primitive=bin/orbit-feature-land; transitions=success:main-updated-archive-committed-session-cleaned|failure:stop-at-failed-phase-with-next-action|retry:rerun-from-observed-phase|stop-restart:resume-without-repeating-completed-mutations|stale:reject-ownership-or-identity-drift
        - Constraints: fixture only
        - Out of scope: product

        ## Proof

        - Verification:
          - focused: passed - focused test
          - broader: passed - docs-lint artifact
          - runtime: not applicable - no runtime proof venue
        - Blast radius: not-required - local change
        - Review: passed - reviewer fixture - human-judgment=not-required
        - Reviewed feature tip: {$featureTip}
        - Acceptance venue: automated
        - Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
        - Accepted feature tip: {$featureTip}
        - Accepted main tip: {$mainTip}

        ## Status

        - State: accepted
        - Blocker: none

        ## Feedback

        - Events: .orbit/feedback.jsonl
        MARKDOWN);
}

/** @return array<string, int> */
function land_quality_check_subgates(): array
{
    $declaration = (string) file_get_contents(repo_path('bin/orbit-quality-subgates.php'));
    $constant = [];

    if (preg_match('/const QUALITY_CHECK_EXPECTED_SUBGATES = \[(.*?)\];/s', $declaration, $constant) !== 1) {
        throw new \RuntimeException('Unable to read finalization quality-check subgates.');
    }

    $matches = [];
    preg_match_all("/'([a-z0-9_]+)'/", $constant[1], $matches);

    return array_fill_keys($matches[1], 0);
}

function land_merge_feature(string $repo): void
{
    land_run($repo, ['git', 'merge', '--no-ff', '--no-edit', 'feature']);
}

function land_write_compact_archive(string $repo, string $worktree): string
{
    $archiveDir = "{$repo}/.orbit/sessions/2026-08-05-120000-feature";
    land_mkdir($archiveDir);
    copy("{$worktree}/.orbit/loop.md", "{$archiveDir}/loop.md");
    $branchTip = trim(new Process(['git', 'rev-parse', 'refs/heads/feature'], $repo)->mustRun()->getOutput());
    $loop = (string) file_get_contents("{$archiveDir}/loop.md");
    preg_match('/^- Accepted feature tip:\s*([0-9a-f]{40})$/m', $loop, $acceptedFeature);
    preg_match('/^- Accepted main tip:\s*([0-9a-f]{40})$/m', $loop, $acceptedMain);

    file_put_contents(
        "{$archiveDir}/orbit-session-archive.json",
        json_encode([
            'schema_version' => 2,
            'archive_mode' => 'compact',
            'branch' => 'feature',
            'candidate_commit' => $branchTip,
            'accepted_feature_tip' => $acceptedFeature[1] ?? '',
            'accepted_main_tip' => $acceptedMain[1] ?? '',
            'copied_entries' => ['loop.md'],
            'entry_digests' => [
                'loop.md' => hash_file('sha256', "{$archiveDir}/loop.md"),
            ],
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL,
    );

    return $archiveDir;
}

function land_write_session_index(string $repo, string $archiveBasename): void
{
    $indexPath = "{$repo}/.orbit/sessions/index.json";
    file_put_contents(
        $indexPath,
        json_encode([
            'schema_version' => 2,
            'generated_from' => '.orbit/sessions/YYYY-MM-DD-HHMMSS-<slug>',
            'record_count' => 1,
            'records' => [
                [
                    'archive' => $archiveBasename,
                    'slug' => 'feature',
                    'timestamp' => '2026-08-05-120000',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            .PHP_EOL,
    );
}

/**
 * @param  array{repo: string, socket: string}  $fixture
 * @param  array<string, string|false>  $environment
 */
function land_run_finalization(array $fixture, string $command, array $environment = []): Process
{
    return land_run_hook($fixture, $command, explicit: true, environment: $environment);
}

/**
 * @param  array{repo: string, socket: string}  $fixture
 * @param  array<string, string|false>  $environment
 */
function land_run_hook(array $fixture, string $command, bool $explicit = true, array $environment = []): Process
{
    $env = land_test_environment($fixture, $environment);

    if ($explicit) {
        $env['ORBIT_FINALIZATION_EXPLICIT'] = '1';
    } else {
        $env['ORBIT_FINALIZATION_EXPLICIT'] = false;
    }

    $process = new Process(
        [PHP_BINARY, repo_path('bin/orbit-codex-pre-tool-use-hook'), $command],
        $fixture['repo'],
        $env,
    );
    $process->run();

    return $process;
}

/**
 * @param  array{repo: string, socket: string}  $fixture
 * @param  list<string>  $args
 * @param  array<string, string|false>  $environment
 */
function land_run_land(array $fixture, array $args, array $environment = [], ?string $cwd = null): Process
{
    $process = new Process(
        [PHP_BINARY, repo_path('bin/orbit-feature-land'), ...$args],
        $cwd ?? $fixture['repo'],
        land_test_environment($fixture, $environment),
    );
    $process->run();

    return $process;
}

/**
 * @param  array{socket: string}  $fixture
 * @param  array<string, string|false>  $environment
 * @return array<string, string|false>
 */
function land_test_environment(array $fixture, array $environment = []): array
{
    return array_merge([
        'ORBIT_TMUX_SOCKET' => $fixture['socket'],
        'TMUX' => false,
        'SOLO_PROJECT_ID' => false,
        'SOLO_PROCESS_ID' => false,
    ], $environment);
}

/**
 * @param  array{repo: string, worktree: string, socket: string, session: string}  $fixture
 */
function land_seed_boundary(array $fixture, string $seed): void
{
    $advanceThroughArchiveCommit = static function () use ($fixture): void {
        land_merge_feature($fixture['repo']);
        $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
        land_write_session_index($fixture['repo'], basename($archive));
        land_commit_sessions($fixture['repo'], $archive);
    };

    match ($seed) {
        'after-merge' => land_merge_feature($fixture['repo']),
        'after-archive' => (static function () use ($fixture): void {
            land_merge_feature($fixture['repo']);
            $archive = land_write_compact_archive($fixture['repo'], $fixture['worktree']);
            land_write_session_index($fixture['repo'], basename($archive));
        })(),
        'after-archive-commit' => $advanceThroughArchiveCommit(),
        'after-kill-session' => (static function () use ($fixture, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            new Process([
                'tmux',
                '-L',
                $fixture['socket'],
                'kill-session',
                '-t',
                '='.$fixture['session'],
            ])->mustRun();
            land_tmux_new_session($fixture['socket'], name: 'feat-keep-server', cwd: $fixture['repo']);
        })(),
        'after-worktree-removed' => (static function () use ($fixture, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            new Process([
                'tmux',
                '-L',
                $fixture['socket'],
                'kill-session',
                '-t',
                '='.$fixture['session'],
            ])->run();
            land_run($fixture['repo'], ['git', 'worktree', 'remove', $fixture['worktree']]);
        })(),
        'after-branch-deleted' => (static function () use ($fixture, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            new Process([
                'tmux',
                '-L',
                $fixture['socket'],
                'kill-session',
                '-t',
                '='.$fixture['session'],
            ])->run();
            if (is_dir($fixture['worktree'])) {
                land_run($fixture['repo'], ['git', 'worktree', 'remove', $fixture['worktree']]);
            }
            if (land_branch_exists($fixture['repo'], 'feature')) {
                land_run($fixture['repo'], ['git', 'branch', '-d', 'feature']);
            }
        })(),
        default => throw new \RuntimeException("unknown seed {$seed}"),
    };
}

function land_commit_sessions(string $repo, string $archiveDir): void
{
    $relative = ltrim(str_replace($repo, '', $archiveDir), '/');
    land_run($repo, ['git', 'add', '--', $relative, '.orbit/sessions/index.json']);
    land_run($repo, ['git', 'commit', '-m', 'Archive session for feature']);
}

/**
 * @param  list<string>  $command
 */
function land_run(string $cwd, array $command): void
{
    new Process($command, $cwd)->mustRun();
}

/**
 * @param  array{root: string, socket: string}  $fixture
 */
function land_remove_fixture(array $fixture): void
{
    if (! str_contains($fixture['root'], '/orbit-feature-land-')) {
        return;
    }

    land_tmux_kill_server($fixture['socket']);
    new Process(['rm', '-rf', $fixture['root']])->run();
}

/**
 * @return list<string>
 */
function land_tmux_socket_dirs(): array
{
    $uid = posix_getuid();
    $dirs = [];
    $tmuxTmpdir = getenv('TMUX_TMPDIR');

    if (is_string($tmuxTmpdir) && $tmuxTmpdir !== '') {
        $dirs[] = rtrim($tmuxTmpdir, '/').'/tmux-'.$uid;
    }

    $dirs[] = rtrim(sys_get_temp_dir(), '/').'/tmux-'.$uid;
    $dirs[] = '/tmp/tmux-'.$uid;

    return array_values(array_unique($dirs));
}

function land_tmux_kill_server(string $socket): void
{
    new Process(['tmux', '-L', $socket, 'kill-server'])->run();

    foreach (land_tmux_socket_dirs() as $dir) {
        $path = $dir.'/'.$socket;

        if (file_exists($path)) {
            unlink($path);
        }
    }
}

function land_mkdir(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, recursive: true) && ! is_dir($path)) {
        throw new \RuntimeException("unable to create directory {$path}");
    }
}

function land_require_tmux(): void
{
    if (! land_tmux_available()) {
        test()->markTestSkipped('tmux is not on PATH');
    }
}

function land_tmux_available(): bool
{
    $process = new Process(['tmux', '-V']);
    $process->run();

    return $process->getExitCode() === 0;
}

function land_tmux_new_session(string $socket, string $name, string $cwd): void
{
    new Process(['tmux', '-L', $socket, 'new-session', '-d', '-s', $name, '-c', $cwd])->mustRun();
}

/**
 * @param  array{socket: string, session: string}  $fixture
 */
function land_tmux_socket_path(array $fixture): string
{
    $process = new Process([
        'tmux',
        '-L',
        $fixture['socket'],
        'display-message',
        '-t',
        $fixture['session'].':',
        '-p',
        '#{socket_path}',
    ]);
    $process->mustRun();

    return trim($process->getOutput());
}

function land_write_fake_tmux(string $root, string $mode, string $sessionPath = '/tmp'): string
{
    $dir = $root.'/fake-tmux-bin';
    land_mkdir($dir);
    $path = $dir.'/tmux';
    $exportedPath = var_export($sessionPath, true);

    if ($mode === 'error') {
        file_put_contents($path, "#!/bin/sh\necho 'error connecting to socket (Permission denied)' >&2\nexit 2\n");
        chmod($path, 0o755);

        return $dir;
    }

    file_put_contents($path, <<<PHP
        #!/usr/bin/env php
        <?php
        declare(strict_types=1);
        \$joined = implode(' ', array_slice(\$argv, 1));
        if (str_contains(\$joined, 'has-session')) {
            exit(0);
        }
        if (str_contains(\$joined, 'kill-session')) {
            exit(0);
        }
        if (str_contains(\$joined, 'session_path')) {
            fwrite(STDOUT, {$exportedPath}.PHP_EOL);
            exit(0);
        }
        if (str_contains(\$joined, '#S')) {
            fwrite(STDOUT, "feat-feature".PHP_EOL);
            exit(0);
        }
        fwrite(STDERR, 'unsupported fake tmux: '.\$joined.PHP_EOL);
        exit(1);
        PHP);
    chmod($path, 0o755);

    return $dir;
}
