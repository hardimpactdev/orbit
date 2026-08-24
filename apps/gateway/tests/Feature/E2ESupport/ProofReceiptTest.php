<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('prints usage for the read-only proof-receipt command', function (): void {
    $process = new Process([repo_path('bin/orbit-feature-proof-receipt'), '--help']);
    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))
        ->toBe('Usage: bin/orbit-feature-proof-receipt [--cwd=<path>] [--loop=<path>] [--json]');
});

it('rejects a missing terminal artifact for the current clean HEAD', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain(
                'docs-only diff requires exact `composer docs-lint` or broader `composer quality-check` evidence',
            );
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('rejects a dirty worktree even when a matching artifact exists', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        proof_receipt_write_docs_lint_artifact($fixture);
        file_put_contents("{$fixture}/docs/mission.md", "dirty docs\n");

        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('dirty')
            ->toContain('clean');
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('rejects a stale artifact that belongs to a previous HEAD', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        proof_receipt_write_docs_lint_artifact($fixture);
        file_put_contents("{$fixture}/docs/mission.md", "second candidate\n");
        proof_receipt_git($fixture, ['add', 'docs/mission.md']);
        proof_receipt_git($fixture, ['commit', '-m', 'Move HEAD']);

        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('does not belong to candidate HEAD');
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('rejects a dirty artifact captured from an unclean candidate', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        proof_receipt_write_docs_lint_artifact($fixture, dirty: true);

        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('was not captured from a clean candidate');
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('accepts current docs-lint evidence for a docs-only candidate and reports the receipt', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        $artifact = proof_receipt_write_docs_lint_artifact($fixture);
        $candidate = proof_receipt_git($fixture, ['rev-parse', 'HEAD']);
        $mtimeBefore = filemtime($artifact);

        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toBe('');

        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)
            ->toMatchArray([
                'ok' => true,
                'candidate' => $candidate,
                'dirty' => false,
                'docs_only' => true,
                'gate' => 'docs-lint',
                'venue' => 'automated',
                'runtime' => 'not applicable',
            ])
            ->and($payload['artifact'])
            ->toEndWith('/.orbit/quality-gates/'.basename($artifact))
            ->and(filemtime($artifact))
            ->toBe($mtimeBefore);
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('accepts a broader quality-check artifact for a docs-only candidate', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        proof_receipt_write_quality_check_artifact($fixture);
        $process = proof_receipt_run($fixture);
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($payload['ok'])
            ->toBeTrue()
            ->and($payload['docs_only'])
            ->toBeTrue()
            ->and($payload['gate'])
            ->toBe('quality-check');
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('requires quality-check evidence for a non-docs candidate', function (): void {
    $fixture = proof_receipt_fixture('apps/cli/app/Commands/FooCommand.php', "<?php\n");

    try {
        $missing = proof_receipt_run($fixture);

        expect($missing->getExitCode())
            ->toBe(2)
            ->and($missing->getErrorOutput())
            ->toContain('non-docs diff requires exact `composer quality-check` evidence');

        proof_receipt_write_docs_lint_artifact($fixture);
        $docsOnly = proof_receipt_run($fixture);

        expect($docsOnly->getExitCode())
            ->toBe(2)
            ->and($docsOnly->getErrorOutput())
            ->toContain('non-docs diff requires exact `composer quality-check` evidence');

        proof_receipt_write_quality_check_artifact($fixture);
        $candidate = proof_receipt_git($fixture, ['rev-parse', 'HEAD']);
        mkdir("{$fixture}/.orbit/evidence", recursive: true);
        file_put_contents("{$fixture}/.orbit/evidence/runtime-proof.txt", "ok\n");
        proof_receipt_write_loop(
            $fixture,
            $candidate,
            runtime: 'passed - candidate='
            .$candidate
            .'; venue=retained-incus; environment=dev-fixture; target=orbit fixture'
            .'; expected=exit 0; observed=exit 0; result=passed'
            .'; evidence=`.orbit/evidence/runtime-proof.txt`',
        );
        $ok = proof_receipt_run($fixture);
        $payload = json_decode($ok->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($ok->getExitCode())
            ->toBe(0, $ok->getErrorOutput())
            ->and($payload)
            ->toMatchArray([
                'ok' => true,
                'docs_only' => false,
                'gate' => 'quality-check',
                'venue' => 'retained-incus',
            ]);
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('fails closed when a non-automated venue has no readable loop packet', function (): void {
    $fixture = proof_receipt_fixture('apps/cli/app/Commands/FooCommand.php', "<?php\n");

    try {
        proof_receipt_write_quality_check_artifact($fixture);
        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('non-automated venue requires a readable `.orbit/loop.md` runtime receipt')
            ->and(json_decode($process->getOutput(), true)['ok'] ?? true)
            ->toBeFalse();
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('requires a structured runtime receipt for a non-automated venue when a loop packet is present', function (): void {
    $fixture = proof_receipt_fixture('apps/cli/app/Commands/FooCommand.php', "<?php\n");

    try {
        proof_receipt_write_quality_check_artifact($fixture);
        $candidate = proof_receipt_git($fixture, ['rev-parse', 'HEAD']);
        proof_receipt_write_loop($fixture, $candidate, runtime: 'pending');

        $missingRuntime = proof_receipt_run($fixture);

        expect($missingRuntime->getExitCode())
            ->toBe(2)
            ->and($missingRuntime->getErrorOutput())
            ->toContain('Verification runtime must be passed');

        mkdir("{$fixture}/.orbit/evidence", recursive: true);
        file_put_contents("{$fixture}/.orbit/evidence/runtime-proof.txt", "ok\n");
        proof_receipt_write_loop(
            $fixture,
            $candidate,
            runtime: 'passed - candidate='
            .$candidate
            .'; venue=retained-incus; environment=dev-fixture; target=orbit fixture'
            .'; expected=exit 0; observed=exit 0; result=passed'
            .'; evidence=`.orbit/evidence/runtime-proof.txt`',
        );

        $ok = proof_receipt_run($fixture);
        $payload = json_decode($ok->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($ok->getExitCode())
            ->toBe(0, $ok->getErrorOutput())
            ->and($payload['ok'])
            ->toBeTrue()
            ->and($payload['venue'])
            ->toBe('retained-incus')
            ->and($payload['runtime'])
            ->toContain('result=passed');
    } finally {
        proof_receipt_remove($fixture);
    }
});

it('does not write files while reporting a receipt', function (): void {
    $fixture = proof_receipt_fixture('docs/mission.md', "docs\n");

    try {
        proof_receipt_write_docs_lint_artifact($fixture);
        $before = proof_receipt_tree_fingerprint($fixture);
        $process = proof_receipt_run($fixture);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(proof_receipt_tree_fingerprint($fixture))
            ->toBe($before);
    } finally {
        proof_receipt_remove($fixture);
    }
});

function proof_receipt_fixture(string $changedPath, string $contents): string
{
    $workspace = sys_get_temp_dir().'/orbit-proof-receipt-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    proof_receipt_git($workspace, ['init', '--initial-branch=main']);
    proof_receipt_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    proof_receipt_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    file_put_contents("{$workspace}/.gitignore", ".orbit/\n");
    proof_receipt_write_quality_label_files($workspace);
    proof_receipt_git($workspace, [
        'add',
        'README.md',
        '.gitignore',
        'bin/orbit-quality-subgates.php',
        'bin/quality-check.sh',
    ]);
    proof_receipt_git($workspace, ['commit', '-m', 'Initial']);
    proof_receipt_git($workspace, ['checkout', '-b', 'feature']);
    $absolute = "{$workspace}/{$changedPath}";
    $directory = dirname($absolute);

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    file_put_contents($absolute, $contents);
    proof_receipt_git($workspace, ['add', $changedPath]);
    proof_receipt_git($workspace, ['commit', '-m', 'Candidate']);
    mkdir("{$workspace}/.orbit", recursive: true);
    mkdir("{$workspace}/.orbit/slices", recursive: true);
    file_put_contents("{$workspace}/.orbit/slices/01-example.md", <<<MARKDOWN
        # Orbit Feature Slice

        - Slice: 01-example
        - Depends on: none

        ## Outcome

        Proof receipt fixture.

        ## Scope

        - Included:
          - fixture
        - Excluded:
          - none

        ## Authority

        - Decisions:
          - fixture contract
        - Product docs:
          - fixture contract

        ## Proof

        - Focused:
          - proof receipt test
        MARKDOWN);

    return $workspace;
}

function proof_receipt_write_quality_label_files(string $workspace): void
{
    require_once repo_path('bin/orbit-quality-subgates.php');

    $declarationBody = implode("\n", array_map(
        static fn (string $label): string => "    '{$label}',",
        QUALITY_CHECK_EXPECTED_SUBGATES,
    ));
    $producerBody = implode("\n", array_map(
        static fn (string $label): string => "    {$label}",
        QUALITY_CHECK_EXPECTED_SUBGATES,
    ));

    mkdir("{$workspace}/bin", recursive: true);
    file_put_contents("{$workspace}/bin/orbit-quality-subgates.php", <<<PHP
        <?php

        declare(strict_types=1);

        const QUALITY_CHECK_EXPECTED_SUBGATES = [
        {$declarationBody}
        ];

        PHP);
    file_put_contents("{$workspace}/bin/quality-check.sh", <<<BASH
        #!/usr/bin/env bash

        CHECK_LABELS=(
        {$producerBody}
        )

        BASH);
}

function proof_receipt_write_docs_lint_artifact(string $workspace, bool $dirty = false): string
{
    return proof_receipt_write_artifact($workspace, 'docs-lint', $dirty);
}

function proof_receipt_write_quality_check_artifact(string $workspace, bool $dirty = false): string
{
    return proof_receipt_write_artifact($workspace, 'quality-check', $dirty);
}

function proof_receipt_write_artifact(string $workspace, string $gate, bool $dirty = false): string
{
    require_once repo_path('bin/orbit-quality-subgates.php');

    $directory = "{$workspace}/.orbit/quality-gates";

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }
    $commit = proof_receipt_git($workspace, ['rev-parse', 'HEAD']);
    $subgates = [];

    if ($gate === 'quality-check') {
        foreach (QUALITY_CHECK_EXPECTED_SUBGATES as $label) {
            $subgates[$label] = 0;
        }
    }

    $endedAt = '2026-08-22T12:00:00+00:00';
    $payload = [
        'gate' => $gate,
        'producer' => $gate === 'quality-check' ? 'quality-check.sh' : 'quality-gate-run',
        'command' => "composer {$gate}",
        'mode' => 'check',
        'exit_code' => 0,
        'duration_ms' => 10,
        'started_at' => '2026-08-22T11:59:00+00:00',
        'ended_at' => $endedAt,
        'git' => [
            'branch' => 'feature',
            'commit' => $commit,
            'dirty' => $dirty,
        ],
        'subgates' => $subgates,
    ];
    $path = "{$directory}/{$gate}-2026-08-22T120000Z.json";
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

    return $path;
}

function proof_receipt_write_loop(string $workspace, string $candidate, string $runtime): void
{
    file_put_contents("{$workspace}/.orbit/loop.md", <<<MARKDOWN
        # Orbit Feature Loop

        - Session: feat-fixture
        - Worktree: {$workspace}
        - Branch: feature

        ## Goal

        Proof receipt fixture.

        ## Scope

        - Owned: fixture
        - Constraints: none
        - Out of scope: none

        ## Slices

        | Slice | State | Checkpoint |
        | --- | --- | --- |
        | `.orbit/slices/01-example.md` | complete | {$candidate} |

        ## Proof

        - Verification:
          - focused: passed
          - broader: passed
          - runtime: {$runtime}
        - Blast radius: not-required - local change
        - Review: pending
        - Reviewed feature tip: none
        - Acceptance venue: retained-incus
        - Acceptance: pending
        - Accepted feature tip: none
        - Accepted main tip: none

        ## Status

        - State: prove
        - Blocker: none

        ## Feedback

        - Events: `.orbit/feedback.jsonl`
        MARKDOWN);
}

function proof_receipt_run(string $workspace): Process
{
    $process = new Process([
        repo_path('bin/orbit-feature-proof-receipt'),
        "--cwd={$workspace}",
        '--json',
    ], $workspace);
    $process->run();

    return $process;
}

/** @param list<string> $arguments */
function proof_receipt_git(string $cwd, array $arguments): string
{
    return trim(new Process(['git', ...$arguments], $cwd)->mustRun()->getOutput());
}

function proof_receipt_tree_fingerprint(string $workspace): string
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relative = $iterator->getSubPathname();

        if ($relative === '.git' || str_starts_with($relative, '.git/')) {
            continue;
        }

        $paths[] = $file->getPathname().':'.$file->getMTime().':'.$file->getSize();
    }

    sort($paths);

    return implode("\n", $paths);
}

function proof_receipt_remove(string $workspace): void
{
    if (str_contains($workspace, '/orbit-proof-receipt-')) {
        new Process(['rm', '-rf', $workspace])->run();
    }
}
