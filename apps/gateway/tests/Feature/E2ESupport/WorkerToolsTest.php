<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('spawns a worker window, pipes the log, and delivers the bootstrap line', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=impl',
                '--cli=grok',
                "--brief={$brief}",
                '--name=impl-1',
                '--ready-delay=1',
                '--json',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $payload = json_decode($process->getOutput(), true);
        expect($payload)
            ->toBeArray()
            ->and($payload['id'])
            ->toBe('impl-1')
            ->and($payload['status'])
            ->toBe('spawned')
            ->and($payload['cli'])
            ->toBe('grok')
            ->and($payload['command'])
            ->toBe(['grok', '--yolo']);

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['tmux']['session'])
            ->toBe('feat-fixture')
            ->and($entry['tmux']['window'])
            ->toBe('impl-1')
            ->and($entry['tmux']['pane_id'])
            ->toStartWith('%')
            ->and($entry['tmux']['pane_pid'])
            ->toBeInt()
            ->and($entry['tmux']['pane_pid'])
            ->toBeGreaterThan(0);

        expect(worker_tools_window_exists($socket, 'feat-fixture', 'impl-1'))->toBeTrue();

        $logPath = $fixture['worktree'].'/.orbit/workers/logs/impl-1.log';
        $seen = worker_tools_wait_for(function () use ($logPath): bool {
            return is_file($logPath) && str_contains((string) file_get_contents($logPath), 'Orbit worker: impl-1.');
        });

        expect($seen)
            ->toBeTrue()
            ->and(filesize($logPath))
            ->toBeGreaterThan(0);
    });
});

it('sends a one-line message into the worker window', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $token = 'orbit-send-'.bin2hex(random_bytes(4));
        $process = worker_tools_run(
            'orbit-worker-send',
            ['impl-1', $token],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $logPath = $fixture['worktree'].'/.orbit/workers/logs/impl-1.log';
        $seen = worker_tools_wait_for(function () use ($logPath, $token): bool {
            return str_contains((string) file_get_contents($logPath), $token);
        });

        expect($seen)->toBeTrue();
    });
});

it('updates heartbeat status and provider_ref', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $process = worker_tools_run(
            'orbit-worker-heartbeat',
            [
                'impl-1',
                '--status=working',
                '--note=busy',
                '--provider-ref=claude://sessions/abc123',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['status'])
            ->toBe('working')
            ->and($entry['note'])
            ->toBe('busy')
            ->and($entry['provider_ref'])
            ->toBe('claude://sessions/abc123')
            ->and($entry['heartbeat_at'])
            ->not->toBe('');
    });
});

it('refuses heartbeat handoff when the handoff file is missing', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $process = worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-1', '--status=handoff'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('handoff file is missing');
    });
});

it('copies a handoff file and sets status=handoff', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $source = $fixture['worktree'].'/notes.md';
        file_put_contents($source, data: "done\n");

        $missing = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $fixture['worktree'].'/missing.md'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($missing->getExitCode())->toBe(2);

        $process = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $destination = $fixture['worktree'].'/.orbit/workers/handoff/impl-1.md';
        expect($destination)
            ->toBeFile()
            ->and((string) file_get_contents($destination))
            ->toBe("done\n");

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['status'])
            ->toBe('handoff')
            ->and($entry['handoff'])
            ->toEndWith('/.orbit/workers/handoff/impl-1.md');
    });
});

it('marks a worker exited when its window is gone', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_tmux($socket, ['kill-window', '-t', '=feat-fixture:impl-1']);

        $process = worker_tools_run(
            'orbit-worker-status',
            ['--json'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $entries = json_decode($process->getOutput(), true);
        expect($entries[0]['status'])
            ->toBe('exited')
            ->and($entries[0]['exited_at'])
            ->not->toBeNull();

        $again = worker_tools_run(
            'orbit-worker-status',
            ['--json'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        $second = json_decode($again->getOutput(), true);
        expect($second[0]['exited_at'])->toBe($entries[0]['exited_at']);
    });
});

it('watch reports handoff immediately', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $source = $fixture['worktree'].'/done.md';
        file_put_contents($source, data: "handoff\n");
        $handoff = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        if ($handoff->getExitCode() !== 0) {
            throw new \RuntimeException($handoff->getErrorOutput().$handoff->getOutput());
        }

        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=900', '--timeout=5'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $event = json_decode(trim($process->getOutput()), true);
        expect($event['event'])
            ->toBe('handoff')
            ->and($event['id'])
            ->toBe('impl-1')
            ->and($event['handoff'])
            ->not->toBeNull();
    });
});

it('watch reports exited when the window disappears', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_tmux($socket, ['kill-window', '-t', '=feat-fixture:impl-1']);

        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=5'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );

        expect($process->getExitCode())->toBe(0);
        $event = json_decode(trim($process->getOutput()), true);
        expect($event['event'])->toBe('exited')->and($event['id'])->toBe('impl-1');
    });
});

it('watch reports stale when the fake CLI stays silent', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1', readyDelay: 0);

        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=1', '--timeout=8'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            12,
        );

        expect($process->getExitCode())->toBe(0);
        $event = json_decode(trim($process->getOutput()), true);
        expect($event['event'])->toBe('stale')->and($event['id'])->toBe('impl-1');
    }, echoing: false);
});

it('watch times out while a worker stays healthy', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-1', '--status=working', '--note=alive'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=900', '--timeout=2'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );

        expect($process->getExitCode())->toBe(3);
        $event = json_decode(trim($process->getOutput()), true);
        expect($event['event'])->toBe('timeout');
    });
});

it('watch returns none when no workers are registered', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=2'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            5,
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and(trim($process->getOutput()))
            ->toBe('{"event":"none"}');
    });
});

it('prepare-worktree fills Session Worktree Branch and creates the tmux session idempotently', function (): void {
    if (! worker_tools_tmux_available()) {
        test()->markTestSkipped('tmux is not on PATH; worker tooling tests require tmux');
    }

    $root = sys_get_temp_dir().'/orbit-prepare-worker-'.bin2hex(random_bytes(6));
    $socket = 'orbit-test-'.bin2hex(random_bytes(4));
    $stubBin = $root.'/stub-bin';

    try {
        worker_tools_make_prepare_repo($root, $stubBin);
        $env = worker_tools_env($socket, $stubBin);
        $first = new Process(
            [$root.'/bin/orbit-prepare-worktree', 'feature-x', '--skip-tests'],
            $root,
            $env,
        );
        $first->setTimeout(30);
        $first->run();

        expect($first->getExitCode())
            ->toBe(0, $first->getErrorOutput().$first->getOutput())
            ->and($first->getOutput())
            ->toContain('WORKTREE_PREPARED')
            ->toContain("TMUX_SESSION=feat-feature-x attach: tmux attach -t '=feat-feature-x'");

        $resolvedWorktree = realpath($root.'/.worktrees/feature-x');
        $worktree = $resolvedWorktree === false ? $root.'/.worktrees/feature-x' : $resolvedWorktree;
        $loop = (string) file_get_contents($worktree.'/.orbit/loop.md');
        expect($loop)
            ->toContain('- Session: feat-feature-x')
            ->toContain('- Worktree: '.$worktree)
            ->toContain('- Branch: feature-x');

        expect(worker_tools_session_exists($socket, 'feat-feature-x'))->toBeTrue();

        file_put_contents(
            $worktree.'/.orbit/loop.md',
            str_replace('- Session: feat-feature-x', '- Session: feat-custom-kept', $loop),
        );

        $second = new Process(
            [$root.'/bin/orbit-prepare-worktree', 'feature-x', '--skip-tests'],
            $root,
            $env,
        );
        $second->setTimeout(30);
        $second->run();

        expect($second->getExitCode())
            ->toBe(0, $second->getErrorOutput().$second->getOutput())
            ->and((string) file_get_contents($worktree.'/.orbit/loop.md'))
            ->toContain('- Session: feat-custom-kept')
            ->and(worker_tools_session_exists($socket, 'feat-feature-x'))
            ->toBeTrue();
    } finally {
        worker_tools_kill_server($socket);
        worker_tools_remove_fixture($root);
    }
});

it('loop lint accepts Session and still accepts a legacy Scratchpad packet', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');
    require_once repo_path('bin/orbit-loop-lint.php');

    $sessionPacket = worker_tools_compact_loop('- Session: feat-example');
    $legacyPacket = worker_tools_compact_loop('- Scratchpad: solo://proj/4/scratchpad/example--1');
    $invalidPacket = worker_tools_compact_loop('- Session: Not Valid');

    expect(compact_loop_problems($sessionPacket))
        ->toBeEmpty()
        ->and(compact_loop_problems($legacyPacket))
        ->toBeEmpty()
        ->and(compact_loop_problems($invalidPacket))
        ->toContain('Session must match feat-<slug> (lowercase, digits, hyphens); current: Not Valid');
});

/**
 * @param  callable(array{root: string, worktree: string}, string, string): void  $callback
 */
function worker_tools_with_session(callable $callback, bool $echoing = true): void
{
    if (! worker_tools_tmux_available()) {
        test()->markTestSkipped('tmux is not on PATH; worker tooling tests require tmux');
    }

    $socket = 'orbit-test-'.bin2hex(random_bytes(4));
    $fixture = worker_tools_make_fixture();
    $fakeBin = worker_tools_make_fake_cli($fixture['root'], $echoing);

    try {
        worker_tools_start_session($fixture['worktree'], $socket, $fakeBin);
        $callback($fixture, $socket, $fakeBin);
    } finally {
        worker_tools_kill_server($socket);
        worker_tools_remove_fixture($fixture['root']);
    }
}

/**
 * @return array{root: string, worktree: string}
 */
function worker_tools_make_fixture(): array
{
    $root = sys_get_temp_dir().'/orbit-worker-tools-'.bin2hex(random_bytes(6));
    mkdir($root, recursive: true);

    new Process(['git', 'init', '-b', 'main'], $root)->mustRun();
    new Process(['git', 'config', 'user.email', 'orbit@example.test'], $root)->mustRun();
    new Process(['git', 'config', 'user.name', 'Orbit Test'], $root)->mustRun();
    mkdir($root.'/.orbit', recursive: true);
    file_put_contents($root.'/.orbit/loop.md', <<<MARKDOWN
        # Orbit Feature Loop

        - Session: feat-fixture
        - Worktree: {$root}
        - Branch: main

        ## Goal

        Fixture.

        ## Scope

        - Owned: worker tools
        - Constraints: none
        - Out of scope: none

        ## Proof

        - Verification:
          - focused: pending
          - broader: pending
          - runtime: not applicable
        - Blast radius: pending
        - Review: pending
        - Reviewed feature tip: none
        - Acceptance venue: automated
        - Acceptance: pending
        - Accepted feature tip: none
        - Accepted main tip: none

        ## Status

        - State: frame
        - Blocker: none

        ## Feedback

        - Events: `.orbit/feedback.jsonl`
        MARKDOWN);
    file_put_contents($root.'/README.md', data: "fixture\n");
    new Process(['git', 'add', '.'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'fixture'], $root)->mustRun();

    return ['root' => $root, 'worktree' => $root];
}

function worker_tools_make_fake_cli(string $root, bool $echoing): string
{
    $bin = $root.'/fake-bin';
    mkdir($bin, recursive: true);
    $script = $echoing
        ? "#!/usr/bin/env bash\nprintf 'grok-fake-ready\\n'\nexec cat\n"
        : "#!/usr/bin/env bash\nexec sleep 3600\n";
    file_put_contents($bin.'/grok', data: $script);
    chmod($bin.'/grok', permissions: 0o755);

    return $bin;
}

function worker_tools_write_brief(string $worktree): string
{
    $directory = $worktree.'/.orbit/workers/briefs';
    mkdir($directory, recursive: true);
    $path = $directory.'/impl-1.md';
    file_put_contents($path, data: "# brief\nDo the work.\n");

    return $path;
}

/**
 * @param  array{root: string, worktree: string}  $fixture
 */
function worker_tools_spawn(
    array $fixture,
    string $socket,
    string $fakeBin,
    string $id,
    int $readyDelay = 1,
): void {
    $brief = worker_tools_write_brief($fixture['worktree']);
    $process = worker_tools_run(
        'orbit-worker-spawn',
        [
            '--role=impl',
            '--cli=grok',
            "--brief={$brief}",
            "--name={$id}",
            "--ready-delay={$readyDelay}",
        ],
        $fixture['worktree'],
        $socket,
        $fakeBin,
    );

    if ($process->getExitCode() !== 0) {
        throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
    }
}

/**
 * @param  list<string>  $args
 *
 * @mago-expect lint:excessive-parameter-list
 */
function worker_tools_run(
    string $script,
    array $args,
    string $cwd,
    string $socket,
    string $fakeBin,
    int $timeout = 30,
): Process {
    $process = new Process(
        [PHP_BINARY, repo_path('bin/'.$script), ...$args],
        $cwd,
        worker_tools_env($socket, $fakeBin),
    );
    $process->setTimeout($timeout);
    $process->run();

    return $process;
}

/**
 * @return array<string, string|false>
 */
function worker_tools_env(string $socket, string $fakeBin): array
{
    $env = getenv();
    $env['ORBIT_TMUX_SOCKET'] = $socket;
    $env['PATH'] = $fakeBin.PATH_SEPARATOR.($env['PATH'] ?? '/usr/bin:/bin');
    $env['TMUX'] = false;

    return $env;
}

function worker_tools_start_session(string $worktree, string $socket, string $fakeBin): void
{
    $process = new Process(
        [worker_tools_tmux_binary(), '-L', $socket, 'new-session', '-d', '-s', 'feat-fixture', '-c', $worktree],
        $worktree,
        worker_tools_env($socket, $fakeBin),
    );
    $process->mustRun();
}

/**
 * @param  list<string>  $args
 */
function worker_tools_tmux(string $socket, array $args): Process
{
    $process = new Process([worker_tools_tmux_binary(), '-L', $socket, ...$args]);
    $process->run();

    return $process;
}

function worker_tools_window_exists(string $socket, string $session, string $window): bool
{
    $process = worker_tools_tmux($socket, [
        'list-windows',
        '-t',
        '='.$session,
        '-F',
        '#{window_name}',
    ]);

    if ($process->getExitCode() !== 0) {
        return false;
    }

    $names = preg_split('/\R/', trim($process->getOutput()));

    foreach ($names === false ? [] : $names as $name) {
        if ($name === $window) {
            return true;
        }
    }

    return false;
}

function worker_tools_session_exists(string $socket, string $session): bool
{
    return worker_tools_tmux($socket, ['has-session', '-t', '='.$session])->getExitCode() === 0;
}

function worker_tools_kill_server(?string $socket): void
{
    if (! is_string($socket) || $socket === '') {
        return;
    }

    worker_tools_tmux($socket, ['kill-server']);
}

function worker_tools_remove_fixture(string $root): void
{
    new Process(['rm', '-rf', $root])->run();
}

function worker_tools_tmux_available(): bool
{
    try {
        worker_tools_tmux_binary();

        return true;
    } catch (\RuntimeException) {
        return false;
    }
}

function worker_tools_tmux_binary(): string
{
    $path = getenv('PATH');

    if (! is_string($path) || $path === '') {
        throw new \RuntimeException('tmux is not on PATH');
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }

        $candidate = $directory.DIRECTORY_SEPARATOR.'tmux';

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new \RuntimeException('tmux is not on PATH');
}

function worker_tools_wait_for(callable $predicate, float $seconds = 8): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($predicate()) {
            return true;
        }

        usleep(100_000);
    }

    return $predicate();
}

function worker_tools_make_prepare_repo(string $root, string $stubBin): void
{
    mkdir($root.'/bin', recursive: true);
    mkdir($root.'/packages/sdk-typescript', recursive: true);
    mkdir($stubBin, recursive: true);
    copy(repo_path('bin/orbit-prepare-worktree'), $root.'/bin/orbit-prepare-worktree');
    chmod($root.'/bin/orbit-prepare-worktree', permissions: 0o755);
    copy(repo_path('LOOP.md.example'), $root.'/LOOP.md.example');
    file_put_contents($root.'/bin/quality-gate-seed-baselines', data: "#!/usr/bin/env bash\nexit 0\n");
    chmod($root.'/bin/quality-gate-seed-baselines', permissions: 0o755);
    file_put_contents($stubBin.'/composer', data: "#!/usr/bin/env bash\nexit 0\n");
    file_put_contents($stubBin.'/npm', data: "#!/usr/bin/env bash\nexit 0\n");
    chmod($stubBin.'/composer', permissions: 0o755);
    chmod($stubBin.'/npm', permissions: 0o755);
    file_put_contents($root.'/packages/sdk-typescript/package.json', data: "{}\n");
    file_put_contents($root.'/.gitignore', data: ".worktrees\n/.worktrees/\n");

    new Process(['git', 'init', '-b', 'main'], $root)->mustRun();
    new Process(['git', 'config', 'user.email', 'orbit@example.test'], $root)->mustRun();
    new Process(['git', 'config', 'user.name', 'Orbit Test'], $root)->mustRun();
    new Process(['git', 'add', '.'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'fixture'], $root)->mustRun();
}

function worker_tools_compact_loop(string $headerLine): string
{
    return <<<MARKDOWN
        # Orbit Feature Loop

        {$headerLine}
        - Worktree: .worktrees/example
        - Branch: feature

        ## Goal

        Prove the compact feature loop.

        ## Scope

        - Owned: loop tooling
        - Constraints: no manual E2E
        - Out of scope: product behavior

        ## Proof

        - Verification:
          - focused: passed - focused test
          - broader: passed - quality artifact
          - runtime: not applicable - no runtime proof venue
        - Blast radius: not-required - local change
        - Review: passed - reviewer example - human-judgment=not-required
        - Reviewed feature tip: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        - Acceptance venue: automated
        - Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
        - Accepted feature tip: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        - Accepted main tip: bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

        ## Status

        - State: accepted
        - Blocker: none

        ## Feedback

        - Events: .orbit/feedback.jsonl
        MARKDOWN;
}
