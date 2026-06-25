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
