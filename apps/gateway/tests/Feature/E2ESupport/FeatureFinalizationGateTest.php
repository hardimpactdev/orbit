<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('blocks a complete loop outcome when retained topology proof is blocked', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: blocked - retained topology unavailable
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

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('required verification is incomplete')
            ->and($process->getErrorOutput())
            ->toContain('Retained topology proof: blocked - retained topology unavailable');
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

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

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

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('missing Retained topology proof verification row');
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

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

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

it('blocks a free-prose loop outcome and echoes the offending value with the allowed enum', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - shipped and verified with all gates green
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('FINALIZATION: BLOCKED')
            ->toContain('shipped and verified with all gates green')
            ->toContain('must be exactly one of: complete | blocked | complete + loop improvement');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a literal pending loop outcome placeholder and echoes it', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - pending
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('loop outcome is blocked or ambiguous: pending');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks placeholder tokens in required verification rows and echoes the offending line', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: tbd
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('placeholder')
            ->toContain('Retained topology proof: tbd');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks unexpanded angle-bracket template placeholders and echoes the offending line', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - <why local cleanup, existing guardrails, or rejection was enough>
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('template placeholders')
            ->toContain('<why local cleanup, existing guardrails, or rejection was enough>');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows a complete loop outcome with explicit non-applicable retained topology proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - `complete`
        - Required verification:
          - Retained topology proof: not applicable - docs-only root harness edit
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Persona: post-feature analyzer
          - Solo process or analyzer: solo process 4242
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'HARNESS.md',
        contents: "# Harness\n\nClarified workflow.\n",
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
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a merge when the feature branch tip equals the merge base', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - no branch diff
          - `composer quality-check`: not applicable - no branch diff
        - Fresh analyzer:
          - Verdict: pass - no missed signals
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
            ->toContain('no commits to merge');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a merge when the feature worktree has uncommitted tracked changes', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );
    file_put_contents(
        filename: "{$worktree}/harness-signals/2026-07-02-example.md",
        data: "# Example\n\nUncommitted edit.\n",
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('uncommitted tracked changes');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks a merge when the fresh analyzer row is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
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
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Fresh analyzer');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('accepts a deferred fresh analyzer row with a warning instead of blocking', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
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
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain('Fresh analyzer deferred');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('warns when accepted durable updates exist but candidate signals are empty', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Candidate Signals While Working

        - none

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
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
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain('reconstructed post-hoc');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('classifies harness-markdown-only diffs as docs class satisfied by docs-lint evidence', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness template and agent-config diff
          - `composer quality-check`: not applicable - harness markdown-class diff; docs-lint passed
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - LOOP.md.example gained the compact single-slice variant.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'LOOP.md.example',
        contents: "# Loop Template\n",
    );
    commit_finalization_gate_file(
        worktree: $worktree,
        path: '.agents/review-personas/example.yaml',
        contents: "persona: example\n",
    );
    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'docs/superpowers/example.txt',
        contents: "session note\n",
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

it('blocks docs-only finalization when docs lint evidence is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - docs-only diff
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

it('allows docs-only finalization with artifact-backed docs lint and no retained topology proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - docs-only diff
          - `composer quality-check`: not applicable - docs-only diff; docs-lint passed
        - Fresh analyzer:
          - Verdict: pass - no missed signals
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
          - Retained topology proof: not applicable - test-only PHP diff
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
          - Retained topology proof: not applicable - no topology-relevant PHP diff
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

it('blocks topology-relevant php finalization when retained topology proof is not applicable', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - topology-relevant PHP diff
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
            ->toContain('topology-relevant PHP diff requires Retained topology proof');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows topology-relevant php finalization when live proof is deferred to a main-based rc release lane', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - merge-boundary proof cannot run before the requested main-based RC artifact is built and deployed; live topology proof is owned by the release acceptance lane with live update:all and Solo command checks for --node=NMBP and --node=mini.
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - release lane deferral accepted.
        - Accepted durable updates:
          - apps/gateway/app/Example.php changed production behavior for a release candidate that must be proven after main-based artifact publication.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - Release acceptance keeps the goal active until `orbit update:all` and Solo --node= checks pass on the live topology.
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
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows docs app php finalization with artifact-backed quality-check and no retained topology proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - docs-app catalog generator PHP has no retained topology target
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - apps/docs/app/Librarian/Example.php changed docs-app catalog tooling.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/docs/app/Librarian/Example.php',
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
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows topology-relevant php finalization with artifact-backed quality-check and retained topology proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: passed - topology dev-1a2b3c, operator VM, command `./apps/cli/orbit node:list --json`
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - no missed signals
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
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks native Orbit Agent finalization on Darwin when host macOS proof is missing', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - native Orbit Agent source diff
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - apps/agent/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/agent/src/main.rs',
        contents: "fn main() {}\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'quality-check',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(
            repo: $repo,
            command: 'git merge feature',
            environment: ['ORBIT_FINALIZATION_HOST_OS_FAMILY' => 'Darwin'],
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('native Orbit Agent diff requires Retained topology proof: passed with host-macos evidence');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows native Orbit Agent finalization on Darwin with host macOS proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: passed - host topology kind=host-macos; host=NMBP; os=Darwin 25.5.0; command `uname -s && sw_vers && open -a Orbit Agent`; evidence `.orbit/evidence/agent-host-macos-computer-use.png`
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - host macOS proof accepted.
        - Accepted durable updates:
          - apps/agent/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/agent/src/main.rs',
        contents: "fn main() {}\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'quality-check',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(
            repo: $repo,
            command: 'git merge feature',
            environment: ['ORBIT_FINALIZATION_HOST_OS_FAMILY' => 'Darwin'],
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks native Orbit Agent finalization on non Darwin implementation hosts', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: passed - host topology kind=host-macos; host=NMBP; os=Darwin 25.5.0; command `uname -s && sw_vers && open -a Orbit Agent`; evidence `.orbit/evidence/agent-host-macos-computer-use.png`
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - host macOS proof accepted.
        - Accepted durable updates:
          - apps/agent/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/agent/src/main.rs',
        contents: "fn main() {}\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'quality-check',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(
            repo: $repo,
            command: 'git merge feature',
            environment: ['ORBIT_FINALIZATION_HOST_OS_FAMILY' => 'Linux'],
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('native Orbit Agent diff requires macOS host topology proof from a Darwin implementation host');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks worktree removal when no matching session archive exists', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(finalization_cleanup_packet());

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('bin/orbit-session-archive');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks branch deletion when no matching session archive exists', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(finalization_cleanup_packet());

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git branch -D feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('bin/orbit-session-archive');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows worktree removal when a matching session archive with loop and manifest exists', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(finalization_cleanup_packet());

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );
    write_finalization_gate_session_archive(repo: $repo, slug: 'feature');

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks cleanup when the matching session archive is missing the agent-sessions manifest', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(finalization_cleanup_packet());

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-02-example.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-06-25T10:00:00+00:00',
    );
    $archiveDir = write_finalization_gate_session_archive(repo: $repo, slug: 'feature');
    unlink("{$archiveDir}/agent-sessions/manifest.json");

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('agent-sessions/manifest.json');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('stays silent with exit zero for unclassifiable commands without explicit mode', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture("# Orbit Current Slice State\n");

    try {
        $process = run_finalization_gate(repo: $repo, command: 'composer quality-check');

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->toBeEmpty()
            ->and($process->getErrorOutput())
            ->toBeEmpty();
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('exits with usage code 64 for explicit unclassifiable invocations through the wrapper', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture("# Orbit Current Slice State\n");

    try {
        $process = run_finalization_check_wrapper(cwd: $repo, args: ['composer', 'quality-check']);

        expect($process->getExitCode())
            ->toBe(64)
            ->and($process->getErrorOutput())
            ->toContain('could not classify');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('lints a filled full-template packet as passing', function (): void {
    $packetDir = make_finalization_lint_dir(full_template_finalization_packet());

    try {
        $process = run_finalization_check_wrapper(
            cwd: $packetDir,
            args: ['--lint', "{$packetDir}/.orbit/loop.md"],
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('lints a compact single-slice packet as passing from the default loop path', function (): void {
    $packetDir = make_finalization_lint_dir(compact_single_slice_finalization_packet());

    try {
        $process = run_finalization_check_wrapper(cwd: $packetDir, args: ['--lint']);

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('lints a packet with unexpanded placeholders as blocked with the offending line', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - <complete | blocked | complete + loop improvement>
        - Required verification:
          - Retained topology proof: pending
          - `composer quality-check`: pending
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - none
        MARKDOWN);

    try {
        $process = run_finalization_check_wrapper(cwd: $packetDir, args: ['--lint']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toContain('BLOCKED')
            ->toContain('<complete | blocked | complete + loop improvement>');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('lints a missing packet file as blocked', function (): void {
    $packetDir = sys_get_temp_dir().'/orbit-finalization-lint-'.bin2hex(random_bytes(6));

    mkdir($packetDir, recursive: true);

    try {
        $process = run_finalization_check_wrapper(cwd: $packetDir, args: ['--lint']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toContain('BLOCKED');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('documents the compact single-slice variant and session archive tool in the loop template', function (): void {
    $template = (string) file_get_contents(repo_path('LOOP.md.example'));

    expect($template)
        ->toContain('Compact Single-Slice Variant')
        ->toContain('- Single-slice: yes -')
        ->toContain('- Parallelization: serial -')
        ->toContain('bin/orbit-session-archive');
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

function finalization_cleanup_packet(): string
{
    return <<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff; docs-lint passed
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - HARNESS.md clarified the workflow.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN;
}

function full_template_finalization_packet(): string
{
    return <<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context

        - Scratchpad: solo://projects/12/scratchpads/34
        - Worktree: .worktrees/example
        - Branch: feature
        - Completed slices:
          - slice-1: hardened the finalization gate
        - Current slice: finalize and merge

        ## Done Contract

        - Active slice start gate:
          - Multi-slice feature roadmap scratchpad exists before worker dispatch: yes
          - `.orbit/loop.md` links the feature roadmap and names the current slice: yes
          - If the source scratchpad lives in another Solo project, execution-project
            scratchpad links back to it and mirrors the roadmap substance: not needed
        - Parallelization scan:
          - Candidate parallel lanes: gate lane, docs lane
          - Serialized lanes, with required dependency/shared-state/provider-capacity/
            merge-order reason: none
          - Deferred lanes (lane -> concrete reason -> owner): none
          - Parallel dispatch started (lane -> Solo process or owner): gate -> solo 7
        - Done when:
          - the gate blocks placeholder packets
        - Evidence:
          - .orbit/evidence/L2-red-finalization-gate.txt
        - Reviewer checks:
          - reviewed by gate tests
        - Stop if:
          - the gate blocks unrelated commands
        - Pivot if:
          - the packet contract changes

        ## Progress

        - Tried: hardened the finalization gate
          Result: pass
          Next: none

        ## Candidate Signals While Working

        - 10:00/loop-review: gate passed free-prose outcomes; fixed via enum validation

        ## Blockers

        - none

        ## Evidence Links

        - bin/orbit-gateway-pest --compact

        ## Harness Signals

        - Searched: harness-signals/
        - Created or updated: none
        - Deferred follow-up: none

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness tooling diff only
          - `composer quality-check`: passed - quality-check artifact
        - Finalization gate fit:
          - harness tooling diff; quality-check artifact covers it
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - Persona: post-feature analyzer
          - Solo process or analyzer: solo process 77
          - Verdict: pass - no missed signals
        - Candidate signals:
          - enum validation -> promote -> landed in the gate
        - Accepted durable updates:
          - bin/orbit-codex-pre-tool-use-hook enum validation
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - none
        MARKDOWN;
}

function compact_single_slice_finalization_packet(): string
{
    return <<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context

        - Scratchpad: none, single-slice
        - Worktree: .worktrees/example
        - Branch: feature
        - Current slice: harden the finalization gate

        ## Done Contract

        - Single-slice: yes - one gate script change with one owned test file
        - Parallelization: serial - single owned file set, no independent lanes
        - Done when:
          - lint passes compact packets
        - Evidence:
          - .orbit/evidence/L2-green-finalization-gate.txt

        ## Progress

        - Tried: compact packet variant
          Result: pass
          Next: none

        ## Candidate Signals While Working

        - none

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff only
          - `composer quality-check`: not applicable - docs-only diff with docs-lint artifact
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Candidate signals:
          - none
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - single-slice hardening; the guardrail landed as tests in the same diff
        MARKDOWN;
}

/**
 * @param  array<string, string>  $environment
 */
function run_finalization_gate(string $repo, string $command, array $environment = []): Process
{
    $process = new Process(
        [
            PHP_BINARY,
            repo_path('bin/orbit-codex-pre-tool-use-hook'),
            $command,
        ],
        $repo,
        $environment,
    );
    $process->run();

    return $process;
}

/**
 * @param  list<string>  $args
 */
function run_finalization_check_wrapper(string $cwd, array $args): Process
{
    $process = new Process([
        'bash',
        repo_path('bin/orbit-feature-finalization-check'),
        ...$args,
    ], $cwd);
    $process->run();

    return $process;
}

function make_finalization_lint_dir(string $loopMarkdown): string
{
    $packetDir = sys_get_temp_dir().'/orbit-finalization-lint-'.bin2hex(random_bytes(6));

    mkdir("{$packetDir}/.orbit", recursive: true);
    file_put_contents(filename: "{$packetDir}/.orbit/loop.md", data: $loopMarkdown);

    return $packetDir;
}

function remove_finalization_lint_dir(string $packetDir): void
{
    if (! str_contains($packetDir, '/orbit-finalization-lint-')) {
        return;
    }

    new Process(['rm', '-rf', $packetDir])->run();
}

function write_finalization_gate_session_archive(string $repo, string $slug): string
{
    $archiveDir = "{$repo}/.orbit/sessions/2026-07-02-101500-{$slug}";

    mkdir("{$archiveDir}/agent-sessions", recursive: true);
    file_put_contents(filename: "{$archiveDir}/loop.md", data: "# Archived loop\n");
    file_put_contents(
        filename: "{$archiveDir}/agent-sessions/manifest.json",
        data: json_encode(['schema_version' => 1, 'sessions' => []], JSON_THROW_ON_ERROR).PHP_EOL,
    );

    return $archiveDir;
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
