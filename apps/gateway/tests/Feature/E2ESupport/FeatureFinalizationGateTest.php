<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('blocks a complete loop outcome when required e2e is blocked', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: blocked - provider topology unavailable
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - None.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - Retry E2E later.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('required verification is incomplete')
            ->and($process->getErrorOutput())
            ->toContain('Durable E2E: blocked - provider topology unavailable');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a complete loop outcome when required verification rows are missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('required verification is incomplete')
            ->and($process->getErrorOutput())
            ->toContain('missing `Required verification` rows');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a complete loop outcome when one required verification row is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - docs-only root harness edit
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('missing `composer quality-check` verification row');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a complete loop outcome when the outcome text contains a blocked verification', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete with topology-blocked retained PTY success-state
        - Accepted durable updates:
          - None.
        - Rejected or already-covered signals:
          - PTY success-state was topology-blocked and treated as already covered.
        - Deferred follow-ups:
          - Complete retained success-state terminal proof later.
        - No-new-signal rationale:
          - No new durable harness rule needed.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('loop outcome is blocked or ambiguous');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows a complete loop outcome with explicit non-applicable e2e', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - docs-only root harness edit
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks docs-only finalization when docs lint evidence is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - docs-only diff
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: not applicable - docs-only diff
        - Accepted durable updates:
          - apps/docs/content/testing/quality-gates.md clarified docs-only finalization.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/docs/content/domains/example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('docs-only diff')
            ->toContain('no latest successful artifact was found for docs-lint');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows docs-only finalization with artifact-backed docs lint and no e2e', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - docs-only diff
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: not applicable - docs-only diff; docs-lint passed
        - Accepted durable updates:
          - apps/docs/content/testing/quality-gates.md clarified docs-only finalization.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/docs/content/domains/example.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks php finalization when quality-check evidence is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - test-only PHP diff
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - tests now cover the finalization gate.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/gateway/tests/Feature/ExampleTest.php',
        contents: "<?php\n\ndeclare(strict_types=1);\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('PHP diff')
            ->toContain('no latest successful artifact was found for quality-check');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks non-docs finalization when quality-check evidence is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - no production PHP diff
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: not applicable - shell script only
        - Accepted durable updates:
          - bin/example changed repository tooling.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'bin/example',
        contents: "#!/usr/bin/env bash\nexit 0\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('non-docs diff requires `composer quality-check: passed`');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks production php finalization when durable e2e is not applicable', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: not applicable - production PHP diff
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - apps/gateway/app/Example.php changed production behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/gateway/app/Example.php',
        contents: "<?php\n\ndeclare(strict_types=1);\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'quality-check',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('production PHP diff requires Durable E2E');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows production php finalization with artifact-backed quality-check and e2e', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: passed - composer test:e2e:docker
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - apps/gateway/app/Example.php changed production behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/gateway/app/Example.php',
        contents: "<?php\n\ndeclare(strict_types=1);\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'quality-check',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'e2e-docker',
        exitCode: 0,
        endedAt: '2026-06-25T10:05:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a passed durable e2e row when matching artifact evidence is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: passed - composer test:e2e:docker
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Durable E2E is marked passed')
            ->and($process->getErrorOutput())
            ->toContain('no latest successful artifact was found for e2e-docker');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a passed durable e2e row when the latest matching artifact failed', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: passed - composer test:e2e:incus
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'e2e-incus',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'e2e-incus',
        exitCode: 1,
        endedAt: '2026-06-25T10:05:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Durable E2E is marked passed')
            ->and($process->getErrorOutput())
            ->toContain('latest e2e-incus artifact exited with code 1');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows a complete loop outcome with artifact-backed durable e2e', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: passed - composer test:e2e:docker
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'e2e-docker',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('maps the docker canary e2e command only to the docker canary artifact gate', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Durable E2E: passed - composer test:e2e:docker:canary
          - Retained CLI ingress VM Solo-terminal check: not applicable - no CLI surface
          - `composer quality-check`: passed - composer quality-check
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'e2e-docker-canary',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

/**
 * @return array{string, string}
 */
function create_finalization_gate_fixture(string $loopMarkdown): array
{
    $repo = sys_get_temp_dir().'/orbit-finalization-gate-'.bin2hex(random_bytes(6));
    $worktree = "{$repo}.feature";

    mkdir($repo, recursive: true);

    run_fixture_command(cwd: $repo, command: ['git', 'init']);
    run_fixture_command(cwd: $repo, command: ['git', 'checkout', '-b', 'main']);
    run_fixture_command(cwd: $repo, command: ['git', 'config', 'user.email', 'orbit@example.test']);
    run_fixture_command(cwd: $repo, command: ['git', 'config', 'user.name', 'Orbit Test']);

    file_put_contents(filename: "{$repo}/HARNESS.md", data: "# Harness\n");
    file_put_contents(filename: "{$repo}/AGENTS.md", data: "# Agents\n");

    run_fixture_command(cwd: $repo, command: ['git', 'add', 'HARNESS.md', 'AGENTS.md']);
    run_fixture_command(cwd: $repo, command: ['git', 'commit', '-m', 'Initial commit']);
    run_fixture_command(cwd: $repo, command: ['git', 'branch', 'feature']);
    run_fixture_command(cwd: $repo, command: ['git', 'worktree', 'add', $worktree, 'feature']);

    mkdir("{$worktree}/.orbit", recursive: true);
    file_put_contents(filename: "{$worktree}/.orbit/loop.md", data: $loopMarkdown);

    return [$repo, $worktree];
}

function run_finalization_gate(string $repo, string $command): Process
{
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-codex-pre-tool-use-hook'),
        $command,
    ], $repo);
    $process->run();

    return $process;
}

function commit_finalization_gate_file(string $worktree, string $path, string $contents): void
{
    $absolutePath = "{$worktree}/{$path}";
    $directory = dirname($absolutePath);

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    file_put_contents(filename: $absolutePath, data: $contents);

    run_fixture_command(cwd: $worktree, command: ['git', 'add', $path]);
    run_fixture_command(cwd: $worktree, command: ['git', 'commit', '-m', "Change {$path}"]);
}

function write_finalization_gate_artifact(string $worktree, string $gate, int $exitCode, string $endedAt): void
{
    $artifactDirectory = "{$worktree}/.orbit/quality-gates";

    if (! is_dir($artifactDirectory)) {
        mkdir($artifactDirectory, recursive: true);
    }

    $payload = [
        'gate' => $gate,
        'command' => "composer {$gate}",
        'exit_code' => $exitCode,
        'duration_ms' => 1000,
        'started_at' => '2026-06-25T09:59:59+00:00',
        'ended_at' => $endedAt,
        'git' => [
            'branch' => 'feature',
            'commit' => trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput()),
            'dirty' => false,
        ],
    ];

    $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $timestamp = str_replace([':', '+'], '-', $endedAt);

    file_put_contents(
        filename: "{$artifactDirectory}/{$gate}-{$timestamp}.json",
        data: $encodedPayload.PHP_EOL,
    );
}

/**
 * @param  list<string>  $command
 */
function run_fixture_command(string $cwd, array $command): void
{
    $process = new Process($command, $cwd);
    $process->mustRun();
}

function remove_finalization_gate_fixture(string $repo, string $worktree): void
{
    new Process(['rm', '-rf', $repo, $worktree])->run();
}
