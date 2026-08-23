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
            ->toBe([
                'env',
                'ORBIT_WORKER_ID=impl-1',
                'ORBIT_WORKER_ROLE=impl',
                'grok',
                '--yolo',
                '--reasoning-effort',
                'medium',
            ]);

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
            ->toBeGreaterThan(0)
            ->and($entry['tmux']['socket'])
            ->toBe(['flag' => '-L', 'value' => $socket]);

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

it('captures a bounded rendered tmux snapshot for an exact worker without changing status', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $token = 'orbit-capture-'.bin2hex(random_bytes(4));
        worker_tools_run(
            'orbit-worker-send',
            ['impl-1', $token],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        $seen = worker_tools_wait_for(function () use ($fixture, $token): bool {
            $log = (string) file_get_contents($fixture['worktree'].'/.orbit/workers/logs/impl-1.log');

            return str_contains($log, $token);
        });
        expect($seen)->toBeTrue();

        $before = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        $missing = worker_tools_run(
            'orbit-worker-capture',
            ['missing-worker'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($missing->getExitCode())
            ->toBe(2)
            ->and($missing->getErrorOutput())
            ->toContain('worker not found');

        $process = worker_tools_run(
            'orbit-worker-capture',
            ['impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain($token);

        $after = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($after['status'])
            ->toBe($before['status'])
            ->and($after['heartbeat_at'])
            ->toBe($before['heartbeat_at']);
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

it('heartbeat rejects terminal --status=handoff and points at orbit-worker-handoff', function (): void {
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
            ->toContain('Usage: bin/orbit-worker-heartbeat <id> --status=<working|blocked>')
            ->toContain('orbit-worker-handoff')
            ->not->toContain('--status=<working|blocked|handoff>');
    });
});

it('copies a handoff file and sets status=handoff', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $source = worker_tools_impl_handoff_source($fixture, "done\n");
        $sha = worker_tools_head($fixture['worktree']);

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

        $destination = $fixture['worktree'].'/.orbit/workers/handoff/impl-1-'.$sha.'.md';
        expect($destination)
            ->toBeFile()
            ->and((string) file_get_contents($destination))
            ->toContain('candidate='.$sha)
            ->toContain("done\n");

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['status'])
            ->toBe('handoff')
            ->and($entry['handoff'])
            ->toEndWith('/.orbit/workers/handoff/impl-1-'.$sha.'.md')
            ->and($entry['heartbeat_at'])
            ->not->toBe('');
    });
});

it('stores the final note atomically with handoff status, path, and heartbeat', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $source = worker_tools_impl_handoff_source($fixture, "done\n");
        $before = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );

        $process = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source, '--note=terminal summary'],
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
            ->toBe('handoff')
            ->and($entry['note'])
            ->toBe('terminal summary')
            ->and($entry['handoff'])
            ->toBeString()
            ->and($entry['heartbeat_at'])
            ->not->toBe($before['heartbeat_at'] ?? '');
    });
});

it('rejects an impl handoff that omits the candidate SHA, names the wrong SHA, or lacks a clean HEAD receipt', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_commit_docs_change($fixture);
        $sha = worker_tools_head($fixture['worktree']);
        $source = $fixture['worktree'].'/.orbit/workers/inbox/notes.md';

        if (! is_dir(dirname($source))) {
            mkdir(dirname($source), recursive: true);
        }

        file_put_contents($source, data: "done without sha\n");
        $missingSha = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($missingSha->getExitCode())
            ->toBe(2)
            ->and($missingSha->getErrorOutput())
            ->toContain('candidate=')
            ->toContain('40-character');

        file_put_contents($source, data: 'candidate='.str_repeat('b', 40)."\n");
        $wrongSha = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($wrongSha->getExitCode())
            ->toBe(2)
            ->and($wrongSha->getErrorOutput())
            ->toContain($sha);

        file_put_contents($source, data: "candidate={$sha}\n");
        $missingReceipt = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($missingReceipt->getExitCode())
            ->toBe(2)
            ->and($missingReceipt->getErrorOutput())
            ->toContain('docs-lint');

        worker_tools_write_docs_lint_artifact($fixture['worktree'], $sha);
        file_put_contents("{$fixture['worktree']}/README.md", data: "dirty after receipt\n");
        $dirty = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($dirty->getExitCode())
            ->toBe(2)
            ->and($dirty->getErrorOutput())
            ->toContain('dirty');
    });
});

it('keeps SHA-keyed impl handoffs inspectable across correction rounds', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $firstSource = worker_tools_impl_handoff_source($fixture, "first\n");
        $firstSha = worker_tools_head($fixture['worktree']);
        $first = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $firstSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());

        worker_tools_commit_docs_change($fixture, "second candidate\n");
        $secondSource = worker_tools_impl_handoff_source($fixture, "second\n");
        $secondSha = worker_tools_head($fixture['worktree']);
        $second = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $secondSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($second->getExitCode())
            ->toBe(0, $second->getErrorOutput().$second->getOutput())
            ->and($fixture['worktree'].'/.orbit/workers/handoff/impl-1-'.$firstSha.'.md')
            ->toBeFile()
            ->and($fixture['worktree'].'/.orbit/workers/handoff/impl-1-'.$secondSha.'.md')
            ->toBeFile()
            ->and((string) file_get_contents($fixture['worktree'].'/.orbit/workers/handoff/impl-1-'.$firstSha.'.md'))
            ->toContain("first\n")
            ->and(
                json_decode(
                    (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
                    true,
                )['handoff'],
            )
            ->toEndWith('/.orbit/workers/handoff/impl-1-'.$secondSha.'.md');
    });
});

it('allows non-impl handoffs without a proof receipt', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        $brief = worker_tools_write_brief($fixture['worktree']);
        $spawn = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=review',
                '--cli=claude',
                "--brief={$brief}",
                '--name=review-1',
                '--ready-delay=1',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($spawn->getExitCode())->toBe(0, $spawn->getErrorOutput().$spawn->getOutput());

        $source = $fixture['worktree'].'/.orbit/workers/inbox/review.md';

        if (! is_dir(dirname($source))) {
            mkdir(dirname($source), recursive: true);
        }

        file_put_contents($source, data: "VERDICT: PASS\n");
        $process = worker_tools_run(
            'orbit-worker-handoff',
            ['review-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($fixture['worktree'].'/.orbit/workers/handoff/review-1.md')
            ->toBeFile();
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
        $source = worker_tools_impl_handoff_source($fixture, "handoff\n");
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

it('helper socket override resolves a session that exists only on the explicit socket', function (): void {
    require_once repo_path('bin/orbit-tmux.php');

    if (! worker_tools_tmux_available()) {
        test()->markTestSkipped('tmux is not on PATH; worker tooling tests require tmux');
    }

    $socketA = 'orbit-test-'.bin2hex(random_bytes(4));
    $socketB = 'orbit-test-'.bin2hex(random_bytes(4));
    $fixture = worker_tools_make_fixture();
    $fakeBin = worker_tools_make_fake_cli($fixture['root'], true);
    $previous = getenv('ORBIT_TMUX_SOCKET');

    try {
        worker_tools_start_session($fixture['worktree'], $socketA, $fakeBin);
        new Process(
            [
                worker_tools_tmux_binary(),
                '-L',
                $socketB,
                'new-session',
                '-d',
                '-s',
                'other',
                '-c',
                $fixture['worktree'],
            ],
            $fixture['worktree'],
            worker_tools_env($socketB, $fakeBin),
        )->mustRun();
        putenv('ORBIT_TMUX_SOCKET='.$socketB);
        $_ENV['ORBIT_TMUX_SOCKET'] = $socketB;

        $explicit = orbit_tmux_has_session('feat-fixture', ['flag' => '-L', 'value' => $socketA]);
        $ambient = orbit_tmux_has_session('feat-fixture');

        expect($explicit['status'])
            ->toBe('ok')
            ->and($ambient['status'])
            ->toBe('not_found');
    } finally {
        if (is_string($previous) && $previous !== '') {
            putenv('ORBIT_TMUX_SOCKET='.$previous);
            $_ENV['ORBIT_TMUX_SOCKET'] = $previous;
        } else {
            putenv('ORBIT_TMUX_SOCKET');
            unset($_ENV['ORBIT_TMUX_SOCKET']);
        }

        worker_tools_kill_server($socketA);
        worker_tools_kill_server($socketB);
        worker_tools_remove_fixture($fixture['root']);
    }
});

it('status and watch do not mark a live worker exited when the ambient socket is wrong', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $wrong = 'orbit-test-'.bin2hex(random_bytes(4));

        $status = worker_tools_run(
            'orbit-worker-status',
            ['--json'],
            $fixture['worktree'],
            $wrong,
            $fakeBin,
        );

        expect($status->getExitCode())
            ->toBe(0, $status->getErrorOutput().$status->getOutput());

        $entries = json_decode($status->getOutput(), true);
        expect($entries[0]['status'])
            ->toBe('spawned')
            ->and($entries[0]['exited_at'])
            ->toBeNull();

        $watch = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=900', '--timeout=2'],
            $fixture['worktree'],
            $wrong,
            $fakeBin,
            8,
        );

        expect($watch->getExitCode())->toBe(3);
        $event = json_decode(trim($watch->getOutput()), true);
        expect($event['event'])->toBe('timeout');
    });
});

it('does not mark a worker exited when tmux lookup fails', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $path = $fixture['worktree'].'/.orbit/workers/impl-1.json';
        $entry = json_decode((string) file_get_contents($path), true);
        $entry['tmux']['socket'] = ['flag' => '-S', 'value' => '/nonexistent/dir/sock'];
        file_put_contents($path, data: json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $status = worker_tools_run(
            'orbit-worker-status',
            ['--json'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($status->getExitCode())
            ->toBe(0, $status->getErrorOutput().$status->getOutput());

        $entries = json_decode($status->getOutput(), true);
        expect($entries[0]['status'])
            ->toBe('spawned')
            ->and($entries[0]['exited_at'])
            ->toBeNull();

        $watch = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=900', '--timeout=2'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );

        expect($watch->getExitCode())->toBe(3);
        $event = json_decode(trim($watch->getOutput()), true);
        expect($event['event'])
            ->toBe('timeout')
            ->and($watch->getErrorOutput())
            ->toContain('WARNING: tmux lookup failed for worker impl-1');
    });
});

it('writes one contiguous bootstrap marker for a silent fake CLI', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=impl',
                '--cli=grok',
                "--brief={$brief}",
                '--name=impl-1',
                '--ready-delay=0',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $logPath = $fixture['worktree'].'/.orbit/workers/logs/impl-1.log';
        $marker = worker_tools_bootstrap_marker($fixture['worktree'], 'impl-1', $brief);
        $seen = worker_tools_wait_for(function () use ($logPath, $marker): bool {
            if (! is_file($logPath)) {
                return false;
            }

            return preg_match('/^'.preg_quote($marker, '/').'$/m', (string) file_get_contents($logPath)) === 1;
        });

        expect($seen)
            ->toBeTrue()
            ->and((string) file_get_contents($logPath))
            ->toContain($marker);
    }, echoing: false);
});

it('sends the spawn bootstrap after the fake CLI is running', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        file_put_contents(
            $fakeBin.'/grok',
            data: "#!/usr/bin/env bash\nread -t 2 -n 1 || true\nprintf 'grok-fake-ready\\n'\nexec cat\n",
        );

        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=impl',
                '--cli=grok',
                "--brief={$brief}",
                '--name=impl-1',
                '--ready-delay=8',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            20,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $logPath = $fixture['worktree'].'/.orbit/workers/logs/impl-1.log';
        $seen = worker_tools_wait_for(function () use ($logPath): bool {
            $log = (string) file_get_contents($logPath);

            return str_contains($log, 'grok-fake-ready') && str_contains($log, 'Orbit worker: impl-1.');
        }, 12);

        expect($seen)->toBeTrue();
    });
});

it('retries bootstrap submit for a delayed interactive CLI', function (string $cli): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin) use ($cli): void {
        file_put_contents(
            $fakeBin.'/'.$cli,
            data: <<<'PHP'
                #!/usr/bin/env php
                <?php
                fwrite(STDOUT, "cli-fake-ready\n");
                fflush(STDOUT);
                $buffer = '';
                $enters = 0;
                $stdin = fopen('php://stdin', 'r');
                if ($stdin === false) {
                    exit(1);
                }
                while (($char = fgetc($stdin)) !== false) {
                    if ($char === "\n" || $char === "\r") {
                        $enters++;
                        if ($enters >= 2) {
                            fwrite(STDOUT, 'accepted-bootstrap:'.$buffer."\n");
                            fflush(STDOUT);
                            break;
                        }
                        continue;
                    }
                    $buffer .= $char;
                }
                while (fgets($stdin) !== false) {
                }
                PHP,
        );
        chmod($fakeBin.'/'.$cli, permissions: 0o755);

        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=review',
                "--cli={$cli}",
                "--brief={$brief}",
                '--name=review-1',
                '--ready-delay=8',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            20,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $logPath = $fixture['worktree'].'/.orbit/workers/logs/review-1.log';
        $marker = worker_tools_bootstrap_marker($fixture['worktree'], 'review-1', $brief);
        $seen = worker_tools_wait_for(function () use ($logPath): bool {
            return is_file($logPath) && str_contains((string) file_get_contents($logPath), 'accepted-bootstrap:');
        }, 12);

        $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        $normalized = str_replace("\r", '', $log);

        expect($seen)
            ->toBeTrue($log)
            ->and($normalized)
            ->toMatch('/^accepted-bootstrap:'.preg_quote($marker, '/').'$/m')
            ->and(preg_match_all('/^accepted-bootstrap:/m', $normalized))
            ->toBe(1);
    });
})->with([
    'grok' => ['grok'],
    'claude' => ['claude'],
]);

it('resolves a first-use trust prompt before submitting bootstrap once', function (string $cli): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin) use ($cli): void {
        file_put_contents(
            $fakeBin.'/'.$cli,
            data: <<<'PHP'
                #!/usr/bin/env php
                <?php
                fwrite(STDOUT, "Accessing workspace:\n");
                fwrite(STDOUT, "Quick safety check: Is this a project you created or one you trust?\n");
                fwrite(STDOUT, "Claude Code'll be able to read, edit, and execute files here.\n");
                fwrite(STDOUT, "1. Yes, I trust this folder\n");
                fwrite(STDOUT, "2. No, exit\n");
                fwrite(STDOUT, "Enter to confirm · Esc to cancel\n");
                fflush(STDOUT);

                $stdin = fopen('php://stdin', 'r');
                if ($stdin === false) {
                    exit(1);
                }

                while (($char = fgetc($stdin)) !== false) {
                    if ($char === "\n" || $char === "\r") {
                        break;
                    }
                }

                fwrite(STDOUT, "Yes, I trust this folder\n");
                fwrite(STDOUT, "cli-editor-ready\n");
                fflush(STDOUT);

                $buffer = '';
                while (($char = fgetc($stdin)) !== false) {
                    if ($char === "\n" || $char === "\r") {
                        if ($buffer === '') {
                            continue;
                        }

                        fwrite(STDOUT, 'accepted-bootstrap:'.$buffer."\n");
                        fflush(STDOUT);
                        break;
                    }

                    $buffer .= $char;
                }

                while (fgets($stdin) !== false) {
                }
                PHP,
        );
        chmod($fakeBin.'/'.$cli, permissions: 0o755);

        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=review',
                "--cli={$cli}",
                "--brief={$brief}",
                '--name=review-1',
                '--ready-delay=8',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            20,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/review-1.json'),
            true,
        );
        $logPath = $fixture['worktree'].'/.orbit/workers/logs/review-1.log';
        $marker = worker_tools_bootstrap_marker($fixture['worktree'], 'review-1', $brief);
        $seen = worker_tools_wait_for(function () use ($logPath): bool {
            return is_file($logPath) && str_contains((string) file_get_contents($logPath), 'accepted-bootstrap:');
        }, 12);
        $log = is_file($logPath) ? str_replace("\r", '', (string) file_get_contents($logPath)) : '';

        expect($entry['id'])
            ->toBe('review-1')
            ->and($entry['role'])
            ->toBe('review')
            ->and($entry['status'])
            ->toBe('spawned')
            ->and($entry['tmux']['window'])
            ->toBe('review-1')
            ->and($seen)
            ->toBeTrue($log)
            ->and($log)
            ->toMatch('/^accepted-bootstrap:'.preg_quote($marker, '/').'$/m')
            ->and(preg_match_all('/^accepted-bootstrap:/m', $log))
            ->toBe(1);
    });
})->with([
    'grok' => ['grok'],
    'claude' => ['claude'],
]);

it('records exact launcher command vectors and assignment-only bootstrap', function (
    string $role,
    string $cli,
    string $name,
    array $argvTail,
): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin) use (
        $role,
        $cli,
        $name,
        $argvTail,
    ): void {
        $brief = worker_tools_write_brief($fixture['worktree']);
        $process = worker_tools_run(
            'orbit-worker-spawn',
            [
                "--role={$role}",
                "--cli={$cli}",
                "--brief={$brief}",
                "--name={$name}",
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
        $marker = worker_tools_bootstrap_marker($fixture['worktree'], $name, $brief);
        $logPath = $fixture['worktree'].'/.orbit/workers/logs/'.$name.'.log';
        $seen = worker_tools_wait_for(function () use ($logPath, $marker): bool {
            return is_file($logPath) && str_contains((string) file_get_contents($logPath), $marker);
        });

        expect($payload)
            ->toBeArray()
            ->and($payload['command'])
            ->toBe([
                'env',
                'ORBIT_WORKER_ID='.$name,
                'ORBIT_WORKER_ROLE='.$role,
                ...$argvTail,
            ])
            ->and($seen)
            ->toBeTrue()
            ->and($marker)
            ->toContain($fixture['worktree'].'/.orbit/loop.md')
            ->toContain('goal authority')
            ->toContain('assignment only')
            ->not->toContain('and execute it.');
    });
})->with([
    'impl grok' => ['impl', 'grok', 'impl-1', ['grok', '--yolo', '--reasoning-effort', 'medium']],
    'review claude' => [
        'review',
        'claude',
        'review-1',
        ['claude', '--dangerously-skip-permissions', '--model', 'opus', '--effort', 'high'],
    ],
    'impl claude' => ['impl', 'claude', 'impl-1', ['claude', '--dangerously-skip-permissions']],
    'impl codex' => ['impl', 'codex', 'impl-1', ['codex', '--yolo']],
]);

it('watch can acknowledge a consumed handoff and wait for a later snapshot on the same worker', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $firstSource = worker_tools_impl_handoff_source($fixture, "first\n");
        $first = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $firstSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());

        $initial = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=3', '--target=impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($initial->getExitCode())->toBe(0, $initial->getErrorOutput().$initial->getOutput());
        $firstEvent = json_decode(trim($initial->getOutput()), true);
        expect($firstEvent['event'])->toBe('handoff')->and($firstEvent['snapshot'])->toBeString();

        $acked = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--timeout=2',
                '--target=impl-1',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($acked->getExitCode())->toBe(3);
        $timeout = json_decode(trim($acked->getOutput()), true);
        expect($timeout['event'])->toBe('timeout');

        worker_tools_commit_docs_change($fixture, "corrected candidate\n");
        $secondSource = worker_tools_impl_handoff_source($fixture, "second\n");
        $second = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $secondSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($second->getExitCode())->toBe(0, $second->getErrorOutput().$second->getOutput());

        $changed = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--timeout=3',
                '--target=impl-1',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($changed->getExitCode())->toBe(0, $changed->getErrorOutput().$changed->getOutput());
        $secondEvent = json_decode(trim($changed->getOutput()), true);
        expect($secondEvent['event'])
            ->toBe('handoff')
            ->and($secondEvent['snapshot'])
            ->not->toBe($firstEvent['snapshot'])->and($secondEvent['handoff'])
            ->not->toBe($firstEvent['handoff']);
    });
});

it('watch ack of one worker still reports a later worker handoff', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $brief = worker_tools_write_brief($fixture['worktree']);
        $spawnReview = worker_tools_run(
            'orbit-worker-spawn',
            [
                '--role=review',
                '--cli=claude',
                "--brief={$brief}",
                '--name=review-1',
                '--ready-delay=1',
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($spawnReview->getExitCode())->toBe(0, $spawnReview->getErrorOutput().$spawnReview->getOutput());

        $implSource = worker_tools_impl_handoff_source($fixture, "impl done\n");
        $implHandoff = worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $implSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($implHandoff->getExitCode())->toBe(0, $implHandoff->getErrorOutput().$implHandoff->getOutput());

        $reviewSource = $fixture['worktree'].'/.orbit/workers/inbox/review.md';
        if (! is_dir(dirname($reviewSource))) {
            mkdir(dirname($reviewSource), recursive: true);
        }
        file_put_contents($reviewSource, data: "VERDICT: PASS\n");
        $reviewHandoff = worker_tools_run(
            'orbit-worker-handoff',
            ['review-1', $reviewSource],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($reviewHandoff->getExitCode())->toBe(0, $reviewHandoff->getErrorOutput().$reviewHandoff->getOutput());

        $first = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=3'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());
        $firstEvent = json_decode(trim($first->getOutput()), true);
        expect($firstEvent['id'])->toBe('impl-1')->and($firstEvent['snapshot'])->toBeString();

        $next = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--timeout=3',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($next->getExitCode())->toBe(0, $next->getErrorOutput().$next->getOutput());
        $secondEvent = json_decode(trim($next->getOutput()), true);
        expect($secondEvent['event'])
            ->toBe('handoff')
            ->and($secondEvent['id'])
            ->toBe('review-1');
    });
});

it('watch still accepts --ignore as cheap compatibility', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $source = worker_tools_impl_handoff_source($fixture, "done\n");
        worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        $process = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=2', '--ignore=impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and(trim($process->getOutput()))
            ->toBe('{"event":"none"}');
    });
});

it('watch acknowledgements of blocked and stale events are revision-sensitive', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $blocked = worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-1', '--status=blocked', '--note=waiting on review'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($blocked->getExitCode())->toBe(0, $blocked->getErrorOutput().$blocked->getOutput());

        $first = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--timeout=3', '--target=impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());
        $firstEvent = json_decode(trim($first->getOutput()), true);
        expect($firstEvent['event'])->toBe('blocked')->and($firstEvent['snapshot'])->toBeString();

        $unchanged = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--timeout=2',
                '--target=impl-1',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($unchanged->getExitCode())->toBe(3);
        $timeout = json_decode(trim($unchanged->getOutput()), true);
        expect($timeout['event'])->toBe('timeout');

        $revised = worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-1', '--status=blocked', '--note=waiting on secrets'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($revised->getExitCode())->toBe(0, $revised->getErrorOutput().$revised->getOutput());

        $changed = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--timeout=3',
                '--target=impl-1',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            8,
        );
        expect($changed->getExitCode())->toBe(0, $changed->getErrorOutput().$changed->getOutput());
        $secondEvent = json_decode(trim($changed->getOutput()), true);
        expect($secondEvent['event'])
            ->toBe('blocked')
            ->and($secondEvent['note'])
            ->toBe('waiting on secrets')
            ->and($secondEvent['snapshot'])
            ->not->toBe($firstEvent['snapshot']);
    });
});

it('watch acknowledgements of stale events resurface after a later heartbeat', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1', readyDelay: 0);

        $first = worker_tools_run(
            'orbit-worker-watch',
            ['--interval=1', '--stale=1', '--timeout=8', '--target=impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            12,
        );
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());
        $firstEvent = json_decode(trim($first->getOutput()), true);
        expect($firstEvent['event'])->toBe('stale')->and($firstEvent['snapshot'])->toBeString();

        $heartbeat = worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-1', '--status=working', '--note=still thinking'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        expect($heartbeat->getExitCode())->toBe(0, $heartbeat->getErrorOutput().$heartbeat->getOutput());

        $changed = worker_tools_run(
            'orbit-worker-watch',
            [
                '--interval=1',
                '--stale=1',
                '--timeout=8',
                '--target=impl-1',
                '--ack='.$firstEvent['snapshot'],
            ],
            $fixture['worktree'],
            $socket,
            $fakeBin,
            12,
        );
        expect($changed->getExitCode())->toBe(0, $changed->getErrorOutput().$changed->getOutput());
        $secondEvent = json_decode(trim($changed->getOutput()), true);
        expect($secondEvent['event'])
            ->toBe('stale')
            ->and($secondEvent['snapshot'])
            ->not->toBe($firstEvent['snapshot']);
    }, echoing: false);
});

it('documents the default watch interval and ack targeting', function (): void {
    $source = (string) file_get_contents(repo_path('bin/orbit-worker-watch'));
    $handoff = (string) file_get_contents(repo_path('bin/orbit-worker-handoff'));

    expect($source)
        ->toContain('[--interval=30]')
        ->toContain('[--target=<id>]')
        ->toContain('[--ack=<snapshot>]')
        ->and($handoff)
        ->toContain('[--note=<text>]');
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

it('stops one worker window and marks it exited', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $before = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        $process = worker_tools_run(
            'orbit-worker-stop',
            ['impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and(trim($process->getOutput()))
            ->toBe('impl-1')
            ->and(worker_tools_window_exists($socket, 'feat-fixture', 'impl-1'))
            ->toBeFalse();

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['status'])
            ->toBe('exited')
            ->and($entry['exited_at'])
            ->not
            ->toBeNull()
            ->and($entry['note'])
            ->toBe($before['note'])
            ->and($entry['handoff'])
            ->toBe($before['handoff']);
    });
});

it('stops only handoff workers with --all-finished and leaves blocked windows alive', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-2');
        $source = worker_tools_impl_handoff_source($fixture, "handoff\n");
        worker_tools_run(
            'orbit-worker-handoff',
            ['impl-1', $source],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );
        worker_tools_run(
            'orbit-worker-heartbeat',
            ['impl-2', '--status=blocked', '--note=waiting'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        $process = worker_tools_run(
            'orbit-worker-stop',
            ['--all-finished'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());

        $ids = preg_split('/\R/', trim($process->getOutput())) ?: [];
        expect($ids)
            ->toBe(['impl-1'])
            ->and(worker_tools_window_exists($socket, 'feat-fixture', 'impl-1'))
            ->toBeFalse()
            ->and(worker_tools_window_exists($socket, 'feat-fixture', 'impl-2'))
            ->toBeTrue();

        $blocked = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-2.json'),
            true,
        );
        expect($blocked['status'])->toBe('blocked');
    });
});

it('is idempotent when the worker window is already gone', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        worker_tools_tmux($socket, ['kill-window', '-t', '=feat-fixture:impl-1']);

        $first = worker_tools_run(
            'orbit-worker-stop',
            ['impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($first->getExitCode())
            ->toBe(0, $first->getErrorOutput().$first->getOutput())
            ->and(trim($first->getOutput()))
            ->toBe('impl-1')
            ->and($first->getErrorOutput())
            ->toContain('worker window is already gone: impl-1');

        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($entry['status'])->toBe('exited');
        $exitedAt = $entry['exited_at'];

        $second = worker_tools_run(
            'orbit-worker-stop',
            ['impl-1'],
            $fixture['worktree'],
            $socket,
            $fakeBin,
        );

        expect($second->getExitCode())
            ->toBe(0)
            ->and(
                json_decode(
                    (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
                    true,
                )['exited_at'],
            )
            ->toBe($exitedAt);
    });
});

it('refuses to stop the caller own pane', function (): void {
    worker_tools_with_session(function (array $fixture, string $socket, string $fakeBin): void {
        worker_tools_spawn($fixture, $socket, $fakeBin, 'impl-1');
        $entry = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        $env = worker_tools_env($socket, $fakeBin);
        $env['TMUX_PANE'] = (string) $entry['tmux']['pane_id'];
        $process = new Process(
            [PHP_BINARY, repo_path('bin/orbit-worker-stop'), 'impl-1'],
            $fixture['worktree'],
            $env,
        );
        $process->setTimeout(30);
        $process->run();

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain("refuses to stop the caller's own pane: impl-1")
            ->and(worker_tools_window_exists($socket, 'feat-fixture', 'impl-1'))
            ->toBeTrue();

        $after = json_decode(
            (string) file_get_contents($fixture['worktree'].'/.orbit/workers/impl-1.json'),
            true,
        );
        expect($after['status'])->toBe('spawned');
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
    $resolved = realpath($root);
    $root = $resolved === false ? $root : $resolved;
    worker_tools_seed_home($root);

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
    file_put_contents($root.'/.gitignore', data: ".orbit/quality-gates/\n.orbit/workers/\nfake-bin/\n");
    new Process(['git', 'add', '.'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'fixture'], $root)->mustRun();
    new Process(['git', 'checkout', '-b', 'feature'], $root)->mustRun();
    file_put_contents($root.'/README.md', data: "feature candidate\n");
    new Process(['git', 'add', 'README.md'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'feature'], $root)->mustRun();

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
    file_put_contents($bin.'/claude', data: $script);
    chmod($bin.'/claude', permissions: 0o755);
    file_put_contents($bin.'/codex', data: $script);
    chmod($bin.'/codex', permissions: 0o755);

    return $bin;
}

function worker_tools_head(string $worktree): string
{
    return trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
}

function worker_tools_commit_docs_change(array $fixture, string $contents = "updated docs\n"): string
{
    file_put_contents($fixture['worktree'].'/README.md', data: $contents);
    new Process(['git', 'add', 'README.md'], $fixture['worktree'])->mustRun();
    new Process(['git', 'commit', '-m', 'Update docs candidate'], $fixture['worktree'])->mustRun();

    return worker_tools_head($fixture['worktree']);
}

function worker_tools_write_docs_lint_artifact(string $worktree, string $commit): void
{
    static $sequence = 0;
    $directory = $worktree.'/.orbit/quality-gates';

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    $sequence++;
    $payload = [
        'gate' => 'docs-lint',
        'producer' => 'quality-gate-run',
        'command' => 'composer docs-lint',
        'mode' => 'check',
        'exit_code' => 0,
        'duration_ms' => 10,
        'started_at' => gmdate('c'),
        'ended_at' => gmdate('c', time() + $sequence),
        'git' => [
            'branch' => 'feature',
            'commit' => $commit,
            'dirty' => false,
        ],
        'subgates' => [],
    ];
    file_put_contents(
        $directory.'/docs-lint-'.$commit.'.json',
        json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

/**
 * @param  array{root: string, worktree: string}  $fixture
 */
function worker_tools_impl_handoff_source(array $fixture, string $body = "done\n"): string
{
    $sha = worker_tools_head($fixture['worktree']);
    worker_tools_write_docs_lint_artifact($fixture['worktree'], $sha);
    $path = $fixture['worktree'].'/.orbit/workers/inbox/handoff-source.md';

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, data: "candidate={$sha}\n{$body}");

    return $path;
}

function worker_tools_bootstrap_marker(string $worktree, string $id, string $brief): string
{
    $briefPath = realpath($brief) ?: $brief;
    $resolvedWorktree = realpath($worktree);
    $loopPath = ($resolvedWorktree === false ? $worktree : $resolvedWorktree).'/.orbit/loop.md';

    return "Orbit worker: {$id}. Read {$loopPath} as the goal authority and {$briefPath} as the assignment only.";
}

function worker_tools_write_brief(string $worktree): string
{
    $directory = $worktree.'/.orbit/workers/briefs';

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }
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
    $env['TMUX_PANE'] = false;
    $home = dirname($fakeBin).'-home';

    if (is_dir($home)) {
        $env['HOME'] = $home;
        $env['ZDOTDIR'] = $home;
    }

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

    foreach (worker_tools_tmux_socket_dirs() as $dir) {
        $path = $dir.'/'.$socket;

        if (file_exists($path)) {
            unlink($path);
        }
    }
}

/**
 * @return list<string>
 */
function worker_tools_tmux_socket_dirs(): array
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

function worker_tools_remove_fixture(string $root): void
{
    new Process(['rm', '-rf', $root, $root.'-home'])->run();
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

function worker_tools_seed_home(string $root): string
{
    $home = $root.'-home';
    mkdir($home, recursive: true);
    file_put_contents($home.'/.zshrc', data: "# orbit worker-tools fixture\n");
    file_put_contents($home.'/.bashrc', data: "# orbit worker-tools fixture\n");

    return $home;
}

function worker_tools_make_prepare_repo(string $root, string $stubBin): void
{
    mkdir($root.'/bin', recursive: true);
    worker_tools_seed_home($root);
    mkdir($root.'/packages/sdk-typescript', recursive: true);
    mkdir($stubBin, recursive: true);
    copy(repo_path('bin/orbit-prepare-worktree'), $root.'/bin/orbit-prepare-worktree');
    chmod($root.'/bin/orbit-prepare-worktree', permissions: 0o755);
    copy(repo_path('LOOP.md.example'), $root.'/LOOP.md.example');
    file_put_contents($root.'/bin/quality-gate-seed-baselines', data: "#!/usr/bin/env bash\nexit 0\n");
    chmod($root.'/bin/quality-gate-seed-baselines', permissions: 0o755);
    file_put_contents($stubBin.'/composer', data: <<<'STUB'
        #!/usr/bin/env bash
        if [ "$*" = "install" ]; then
            mkdir -p vendor
            printf '<?php\n' >vendor/autoload.php
        fi
        exit 0

        STUB);
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
