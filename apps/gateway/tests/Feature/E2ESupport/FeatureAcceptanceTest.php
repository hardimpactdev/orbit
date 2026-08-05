<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('shows global help without requiring a loop packet', function (string $argument): void {
    $workspace = sys_get_temp_dir().'/orbit-acceptance-help-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);

    try {
        $process = new Process([repo_path('bin/orbit-feature-acceptance'), $argument], $workspace);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))
            ->toBe('Usage: bin/orbit-feature-acceptance ready|accept|reprove|invalidate|show [options]')
            ->and($process->getErrorOutput())
            ->toBe('')
            ->and(is_dir("{$workspace}/.orbit"))
            ->toBeFalse();
    } finally {
        acceptance_test_remove($workspace);
    }
})->with([
    'long help' => '--help',
    'short help' => '-h',
]);

it('derives the minimum acceptance venue from changed files', function (array $files, string $venue): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    expect(orbitLoopAcceptanceVenue($files))->toBe($venue);
})->with([
    'docs only' => [['apps/docs/content/mission.md'], 'automated'],
    'test only' => [['apps/cli/tests/Feature/Commands/FooTest.php'], 'automated'],
    'repository tooling' => [['bin/orbit-example'], 'automated'],
    'generated session index' => [['.orbit/sessions/index.json'], 'automated'],
    'cli command' => [['apps/cli/app/Commands/FooCommand.php'], 'retained-incus'],
    'node runtime' => [['apps/gateway/app/Actions/Node/RepairNode.php'], 'retained-incus'],
    'tooling and cli command' => [
        [
            'bin/orbit-example',
            'apps/cli/app/Commands/FooCommand.php',
        ],
        'retained-incus',
    ],
    'gateway frontend' => [['apps/gateway/resources/js/app.js'], 'browser'],
    'native mac app' => [['apps/macos/src/main.rs'], 'host-macos'],
]);

it('allows stronger acceptance venues without allowing downgrades', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    expect(orbitLoopVenueSatisfies('retained-incus', 'automated'))
        ->toBeTrue()
        ->and(orbitLoopVenueSatisfies('automated', 'retained-incus'))
        ->toBeFalse()
        ->and(orbitLoopVenueSatisfies('browser', 'retained-incus'))
        ->toBeFalse()
        ->and(orbitLoopVenueSatisfies('host-macos', 'browser'))
        ->toBeFalse();
});

it('records user acceptance against the clean feature and current main tips', function (): void {
    $fixture = acceptance_test_workspace('user', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer 100 - observable',
            venue: 'retained-incus',
        );

        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#acceptance-1'],
            "Looks correct; accept.\n",
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $events = acceptance_test_feedback($fixture);

        expect($loop)
            ->toContain('- State: accepted')
            ->toContain('- Acceptance: accepted - user @ codex://threads/example#acceptance-1')
            ->toContain('- Accepted feature tip: '.acceptance_test_git($fixture, ['rev-parse', 'HEAD']))
            ->toContain('- Accepted main tip: '.acceptance_test_git($fixture, ['rev-parse', 'main']))
            ->not
            ->toContain('Looks correct; accept.')
            ->and($events)
            ->toHaveCount(1)
            ->and($events[0])
            ->toMatchArray([
                'type' => 'feedback.recorded',
                'raw_text' => 'Looks correct; accept.',
                'session_ref' => 'codex://threads/example#acceptance-1',
                'surface' => 'acceptance.retained-incus',
                'actionable' => false,
            ]);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires reviewer-confirmed no human judgment before automatically accepting repository tooling', function (): void {
    $observable = acceptance_test_workspace('automated-blocked', 'bin/orbit-observable');
    $nonObservable = acceptance_test_workspace('automated-pass', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $observable,
            state: 'accept',
            review: 'passed - reviewer 1 - human-judgment=required',
            venue: 'automated',
        );
        acceptance_test_seed_loop(
            $nonObservable,
            state: 'accept',
            review: 'passed - reviewer 2 - human-judgment=not-required',
            venue: 'automated',
        );

        $blocked = acceptance_test_run($observable, ['accept', '--actor=automated']);
        $accepted = acceptance_test_run($nonObservable, ['accept', '--actor=automated']);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('reviewer-confirmed no human judgment')
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$nonObservable}/.orbit/loop.md"))
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment')
            ->toContain('- State: accepted');
    } finally {
        acceptance_test_remove($observable);
        acceptance_test_remove($nonObservable);
    }
});

it('does not require retained runtime proof for repository tooling', function (): void {
    $fixture = acceptance_test_workspace('automated-tooling', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );
        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        file_put_contents(
            "{$fixture}/.orbit/loop.md",
            preg_replace(
                '/^(\s*)- runtime: .+$/m',
                '$1- runtime: not applicable - repository tooling',
                $loop,
                1,
            ) ?? $loop,
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($ready->getExitCode())
            ->toBe(0, $ready->getErrorOutput())
            ->and($ready->getOutput())
            ->toContain('ACCEPTANCE READY venue=automated actor=automated')
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance venue: automated')
            ->toContain('- runtime: not applicable - repository tooling')
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('allows a local change to record that blast-radius closure is not required', function (): void {
    $fixture = acceptance_test_workspace('local-blast-radius', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: 'not-required - repository-local tooling change',
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($ready->getExitCode())
            ->toBe(0, $ready->getErrorOutput())
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Blast radius: not-required - repository-local tooling change')
            ->toContain('- State: accepted');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires high-authority changes to record complete blast-radius closure evidence', function (): void {
    $notRequired = acceptance_test_workspace('authority-blast-radius-not-required', 'PRODUCT_DECISIONS.md');
    $bareComplete = acceptance_test_workspace('authority-blast-radius-bare-complete', 'PRODUCT_DECISIONS.md');
    $closed = acceptance_test_workspace('authority-blast-radius-complete', 'PRODUCT_DECISIONS.md');

    try {
        acceptance_test_seed_loop(
            $notRequired,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: 'not-required - incorrectly treated as local',
        );
        acceptance_test_seed_loop(
            $bareComplete,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: 'complete',
        );
        acceptance_test_seed_loop(
            $closed,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: "complete - evidence=rg 'transport contract' apps packages bin; result=all affected surfaces aligned",
        );

        $notRequiredReady = acceptance_test_run($notRequired, ['ready']);
        $bareCompleteReady = acceptance_test_run($bareComplete, ['ready']);
        $closedReady = acceptance_test_run($closed, ['ready']);
        $closedAccepted = acceptance_test_run($closed, ['accept', '--actor=automated']);

        expect($notRequiredReady->getExitCode())
            ->toBe(2)
            ->and(strtolower($notRequiredReady->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('complete')
            ->and($bareCompleteReady->getExitCode())
            ->toBe(2)
            ->and(strtolower($bareCompleteReady->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('evidence')
            ->toContain('result')
            ->and($closedReady->getExitCode())
            ->toBe(0, $closedReady->getErrorOutput())
            ->and($closedAccepted->getExitCode())
            ->toBe(0, $closedAccepted->getErrorOutput());
    } finally {
        acceptance_test_remove($notRequired);
        acceptance_test_remove($bareComplete);
        acceptance_test_remove($closed);
    }
});

it('treats every product domain contract as high authority for blast-radius closure', function (): void {
    $fixture = acceptance_test_workspace(
        'domain-contract-blast-radius',
        'apps/docs/content/domains/apps.md',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: 'not-required - incorrectly treated as local',
        );

        $ready = acceptance_test_run($fixture, ['ready']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and(strtolower($ready->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('complete');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('keeps a renamed authority source in the blast-radius closure boundary', function (): void {
    $fixture = acceptance_test_rename_workspace(
        'renamed-authority-blast-radius',
        'PRODUCT_DECISIONS.md',
        'docs/decisions-archive.md',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: 'not-required - incorrectly inspected only the rename destination',
        );

        $ready = acceptance_test_run($fixture, ['ready']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and(strtolower($ready->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('complete');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('blocks readiness and acceptance while blast-radius closure is unresolved', function (string $blastRadius): void {
    $status = explode(' ', $blastRadius, 2)[0];
    $fixture = acceptance_test_workspace("unresolved-blast-radius-{$status}", 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
            blastRadius: $blastRadius,
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and(strtolower($ready->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain($status)
            ->and($accepted->getExitCode())
            ->toBe(2)
            ->and(strtolower($accepted->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain($status);
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'pending' => 'pending',
    'gaps' => 'gaps - gateway-owned workload SSH remains unclassified',
]);

it('keeps diff-derived proof separate from the automated acceptance actor', function (): void {
    $fixture = acceptance_test_workspace(
        'automated-retained-proof',
        'apps/cli/app/Commands/FooCommand.php',
    );
    $weakVenue = acceptance_test_workspace(
        'automated-weak-proof',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );
        acceptance_test_seed_loop(
            $weakVenue,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);
        $weakened = acceptance_test_run($weakVenue, ['ready', '--venue=automated']);

        expect($ready->getExitCode())
            ->toBe(0, $ready->getErrorOutput())
            ->and($ready->getOutput())
            ->toContain('ACCEPTANCE READY venue=retained-incus actor=automated')
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance venue: retained-incus')
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment')
            ->and($weakened->getExitCode())
            ->toBe(2)
            ->and($weakened->getErrorOutput())
            ->toContain('acceptance venue automated is weaker than retained-incus');
    } finally {
        acceptance_test_remove($fixture);
        acceptance_test_remove($weakVenue);
    }
});

it('matches the acceptance actor to the reviewer human-judgment decision', function (): void {
    $required = acceptance_test_workspace('human-required', 'bin/orbit-required');
    $notRequired = acceptance_test_workspace('human-not-required', 'bin/orbit-not-required');

    try {
        acceptance_test_seed_loop(
            $required,
            state: 'accept',
            review: 'passed - reviewer - human-judgment=required',
            venue: 'retained-incus',
        );
        acceptance_test_seed_loop(
            $notRequired,
            state: 'accept',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );

        $automated = acceptance_test_run($required, ['accept', '--actor=automated']);
        $unnecessaryUser = acceptance_test_run(
            $notRequired,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#unnecessary'],
            "I should not need to run this.\n",
        );

        expect($automated->getExitCode())
            ->toBe(2)
            ->and($automated->getErrorOutput())
            ->toContain('human judgment is required')
            ->and($unnecessaryUser->getExitCode())
            ->toBe(2)
            ->and($unnecessaryUser->getErrorOutput())
            ->toContain('human acceptance is unnecessary')
            ->and("{$notRequired}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        acceptance_test_remove($required);
        acceptance_test_remove($notRequired);
    }
});

it('requires the diff-derived runtime proof before any retained acceptance', function (): void {
    $fixture = acceptance_test_workspace(
        'missing-runtime-proof',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );
        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        file_put_contents(
            "{$fixture}/.orbit/loop.md",
            preg_replace(
                '/^(\s*)- runtime: .+$/m',
                '$1- runtime: not applicable - skipped',
                $loop,
                1,
            ) ?? $loop,
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('Verification runtime must be passed for acceptance venue retained-incus')
            ->and($accepted->getExitCode())
            ->toBe(2)
            ->and($accepted->getErrorOutput())
            ->toContain('Verification runtime must be passed for acceptance venue retained-incus');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects deferred or failed final-hop runtime claims and accepts structured completed proof', function (
    string $runtimeDetail,
    bool $shouldPass,
    ?string $errorNeedle,
): void {
    $fixture = acceptance_test_workspace(
        'runtime-final-hop-'.substr(md5($runtimeDetail), 0, 10),
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );
        $tip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        $runtime = str_replace('<TIP>', $tip, $runtimeDetail);
        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        file_put_contents(
            "{$fixture}/.orbit/loop.md",
            preg_replace('/^(\s*)- runtime: .+$/m', '$1- runtime: '.$runtime, $loop, 1) ?? $loop,
        );
        $before = (string) file_get_contents("{$fixture}/.orbit/loop.md");

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        if ($shouldPass) {
            expect($ready->getExitCode())
                ->toBe(0, $ready->getErrorOutput())
                ->and($accepted->getExitCode())
                ->toBe(0, $accepted->getErrorOutput())
                ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
                ->toContain('- State: accepted');
        } else {
            expect($ready->getExitCode())
                ->toBe(2)
                ->and($ready->getErrorOutput())
                ->toContain((string) $errorNeedle)
                ->toContain('remain in PROVE')
                ->and($accepted->getExitCode())
                ->toBe(2)
                ->and($accepted->getErrorOutput())
                ->toContain((string) $errorNeedle)
                ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
                ->toBe($before)
                ->toContain('- State: prove');
        }
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'post-LAND deferral' => [
        'passed - live update failed writing bare /etc/caddy; post-LAND durable re-proof follows',
        false,
        'final hop',
    ],
    'post-merge remains required' => [
        'passed - automated host-boundary simulation; immediate post-merge live candidate verification remains required',
        false,
        'final hop',
    ],
    'follow-up closure proof' => [
        'passed - packaging contract green; live rebuild is follow-up closure proof after LAND',
        false,
        'final hop',
    ],
    'free-form without receipt' => [
        'passed - retained fixture',
        false,
        'structured runtime receipt',
    ],
    'valid structured receipt' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0 with no failures; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid repaired failure narrative' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=agent-1; expected=healthy; observed=healthy after previous failure repaired; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
]);

it('requires a human-judgment decision on reviewer PASS', function (): void {
    $fixture = acceptance_test_workspace('missing-human-judgment', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer without decision',
            venue: 'retained-incus',
        );

        $process = acceptance_test_run($fixture, ['ready']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Review must record human-judgment=required or human-judgment=not-required');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('blocks acceptance while actionable feedback is unresolved', function (): void {
    $fixture = acceptance_test_workspace('unresolved-feedback', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'retained-incus',
        );
        $event = [
            'schema_version' => 1,
            'type' => 'feedback.recorded',
            'id' => 'feedback-unresolved',
            'recorded_at' => '2026-07-10T18:00:00Z',
            'raw_text' => 'The command still appears frozen.',
            'session_ref' => 'codex://threads/example#feedback',
            'candidate_commit' => acceptance_test_git($fixture, ['rev-parse', 'HEAD']),
            'surface' => 'cli.progress',
            'actionable' => true,
            'context' => [],
            'evidence' => [],
        ];
        file_put_contents(
            "{$fixture}/.orbit/feedback.jsonl",
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $process = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('unresolved actionable feedback: feedback-unresolved')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance: pending')
            ->toContain('- State: accept');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects all nonignored untracked files before readiness or acceptance', function (): void {
    $fixture = acceptance_test_workspace('untracked', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - non-observable',
            venue: 'retained-incus',
        );
        file_put_contents("{$fixture}/forgotten.php", "<?php\n");

        $ready = acceptance_test_run($fixture, ['ready', '--venue=retained-incus']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('forgotten.php')
            ->toContain('accepted HEAD');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires reviewer PASS to be bound to the exact candidate HEAD', function (): void {
    $fixture = acceptance_test_workspace('reviewed-tip', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - non-observable',
            venue: 'retained-incus',
        );
        file_put_contents("{$fixture}/bin/orbit-later", "later\n");
        acceptance_test_git($fixture, ['add', 'bin/orbit-later']);
        acceptance_test_git($fixture, ['commit', '-m', 'Move candidate after review']);

        $ready = acceptance_test_run($fixture, ['ready', '--venue=retained-incus']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('reviewed feature tip does not equal candidate HEAD')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: prove')
            ->toContain('- Acceptance: pending');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('does not ask for or record acceptance before current main is integrated', function (): void {
    $fixture = acceptance_test_workspace('main-before-acceptance', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $before = (string) file_get_contents("{$fixture}/.orbit/loop.md");

        acceptance_test_git($fixture, ['checkout', 'main']);
        file_put_contents("{$fixture}/README.md", "# Main moved before acceptance\n");
        acceptance_test_git($fixture, ['add', 'README.md']);
        acceptance_test_git($fixture, ['commit', '-m', 'Move main before acceptance']);
        acceptance_test_git($fixture, ['checkout', 'feature']);

        $ready = acceptance_test_run($fixture, ['ready', '--venue=retained-incus']);
        $accepted = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#stale-acceptance'],
            "Do not consume this acceptance.\n",
        );

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('main advanced before acceptance')
            ->and($accepted->getExitCode())
            ->toBe(2)
            ->and($accepted->getErrorOutput())
            ->toContain('main advanced before acceptance')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($before)
            ->and("{$fixture}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('invalidating accepted feedback resets the reviewer identity for the FIX delta', function (): void {
    $fixture = acceptance_test_workspace('review-reset', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'retained-incus',
        );
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);
        expect($accepted->getExitCode())->toBe(0, $accepted->getErrorOutput());

        $invalidated = acceptance_test_run($fixture, ['invalidate', '--reason=UX correction requested']);

        expect($invalidated->getExitCode())
            ->toBe(0, $invalidated->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Review: fix - acceptance invalidated - UX correction requested')
            ->toContain('- Reviewed feature tip: none')
            ->toContain('- State: build');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires main integration and a fresh proof and acceptance when main moves', function (): void {
    $fixture = acceptance_test_workspace('reprove', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $accepted = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#acceptance'],
            "Accepted.\n",
        );
        expect($accepted->getExitCode())->toBe(0, $accepted->getErrorOutput());
        $acceptedLoop = (string) file_get_contents("{$fixture}/.orbit/loop.md");

        acceptance_test_git($fixture, ['checkout', 'main']);
        file_put_contents("{$fixture}/README.md", "# Main moved\n");
        acceptance_test_git($fixture, ['add', 'README.md']);
        acceptance_test_git($fixture, ['commit', '-m', 'Move main']);
        acceptance_test_git($fixture, ['checkout', 'feature']);

        $show = acceptance_test_run($fixture, ['show', '--json']);
        $status = json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toHaveKey('status', 'reproof-required');

        $reprove = acceptance_test_run($fixture, ['reprove', '--verification-ref=focused-proof-after-main-move']);

        expect($reprove->getExitCode())
            ->toBe(2)
            ->and($reprove->getErrorOutput())
            ->toContain('integrate main into the feature')
            ->toContain('PROVE and ACCEPT')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($acceptedLoop);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects unsafe user acceptance source references before changing durable state', function (): void {
    $fixture = acceptance_test_workspace('unsafe-source', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $before = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $token = 'gho_'.str_repeat('a', 24);
        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', "--source-ref=codex://threads/example?token={$token}"],
            "Accept.\n",
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('safe Codex or Solo source reference')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($before)
            ->and("{$fixture}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('blocks high-confidence candidate secrets before acceptance', function (
    #[\SensitiveParameter]
    string $secret,
    string $rule,
): void {
    $fixture = acceptance_test_workspace('candidate-secret-'.$rule, 'bin/orbit-example');

    try {
        file_put_contents("{$fixture}/bin/orbit-example", $secret."\n");
        acceptance_test_git($fixture, ['add', 'bin/orbit-example']);
        acceptance_test_git($fixture, ['commit', '-m', 'Add secret-shaped candidate']);
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'retained-incus',
        );

        $process = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($rule)
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance: pending');
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'aws access key' => ['AKIA'.str_repeat('A', 16), 'aws-access-key'],
    'github oauth token' => ['gho_'.str_repeat('b', 24), 'github-token'],
    'Laravel app key' => ['APP_KEY='.'base64:'.str_repeat('C', 43).'=', 'laravel-app-key'],
    'encrypted private key' => ['-----BEGIN '.'ENCRYPTED PRIVATE KEY-----', 'private-key'],
]);

function acceptance_test_workspace(string $suffix, string $changedPath): string
{
    $workspace = sys_get_temp_dir().'/orbit-acceptance-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    acceptance_test_git($workspace, ['init', '--initial-branch=main']);
    acceptance_test_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    acceptance_test_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    file_put_contents("{$workspace}/.gitignore", ".orbit/\n");
    acceptance_test_git($workspace, ['add', 'README.md', '.gitignore']);
    acceptance_test_git($workspace, ['commit', '-m', 'Initial']);
    acceptance_test_git($workspace, ['checkout', '-b', 'feature']);
    $absolute = "{$workspace}/{$changedPath}";
    $directory = dirname($absolute);

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    file_put_contents($absolute, "candidate\n");
    acceptance_test_git($workspace, ['add', $changedPath]);
    acceptance_test_git($workspace, ['commit', '-m', 'Candidate']);
    mkdir("{$workspace}/.orbit", recursive: true);

    return $workspace;
}

function acceptance_test_rename_workspace(string $suffix, string $sourcePath, string $destinationPath): string
{
    $workspace = sys_get_temp_dir().'/orbit-acceptance-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    acceptance_test_git($workspace, ['init', '--initial-branch=main']);
    acceptance_test_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    acceptance_test_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    file_put_contents("{$workspace}/.gitignore", ".orbit/\n");
    file_put_contents("{$workspace}/{$sourcePath}", "authority\n");
    acceptance_test_git($workspace, ['add', 'README.md', '.gitignore', $sourcePath]);
    acceptance_test_git($workspace, ['commit', '-m', 'Initial']);
    acceptance_test_git($workspace, ['checkout', '-b', 'feature']);
    $destinationDirectory = dirname("{$workspace}/{$destinationPath}");

    if (! is_dir($destinationDirectory)) {
        mkdir($destinationDirectory, recursive: true);
    }

    acceptance_test_git($workspace, ['mv', $sourcePath, $destinationPath]);
    acceptance_test_git($workspace, ['commit', '-m', 'Rename authority file']);
    mkdir("{$workspace}/.orbit", recursive: true);

    return $workspace;
}

function acceptance_test_seed_loop(
    string $fixture,
    string $state,
    string $review,
    string $venue,
    string $blastRadius = 'not-required - local change',
): void {
    $reviewedTip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
    // Seed a candidate-bound structured receipt even when the packet still says
    // venue=automated so ready can upgrade to the diff-derived retained venue.
    $runtime = acceptance_test_structured_runtime(
        $reviewedTip,
        $venue === 'automated' ? 'retained-incus' : $venue,
    );

    file_put_contents("{$fixture}/.orbit/loop.md", <<<MARKDOWN
        # Orbit Feature Loop

        - Scratchpad: solo://proj/4/scratchpad/example--1
        - Worktree: {$fixture}
        - Branch: feature

        ## Goal

        Acceptance fixture.

        ## Scope

        - Owned: fixture
        - Constraints: no manual E2E
        - Out of scope: none

        ## Proof

        - Verification:
          - focused: passed - initial focused proof
          - broader: passed - initial broader proof
          - runtime: {$runtime}
        - Blast radius: {$blastRadius}
        - Review: {$review}
        - Reviewed feature tip: {$reviewedTip}
        - Acceptance venue: {$venue}
        - Acceptance: pending
        - Accepted feature tip: none
        - Accepted main tip: none

        ## Status

        - State: {$state}
        - Blocker: none

        ## Feedback

        - Events: `.orbit/feedback.jsonl`
        MARKDOWN);
}

function acceptance_test_structured_runtime(
    string $candidate,
    string $venue = 'retained-incus',
    string $observed = 'exit 0',
): string {
    return 'passed - candidate='.$candidate
        .'; venue='.$venue
        .'; environment=dev-fixture'
        .'; target=orbit fixture'
        .'; expected=exit 0'
        .'; observed='.$observed
        .'; result=passed'
        .'; evidence=`.orbit/evidence/runtime-proof.txt`';
}

/** @param list<string> $arguments */
function acceptance_test_run(string $fixture, array $arguments, string $input = ''): Process
{
    $process = new Process([
        repo_path('bin/orbit-feature-acceptance'),
        ...$arguments,
        "--cwd={$fixture}",
        "--loop={$fixture}/.orbit/loop.md",
    ], $fixture);
    $process->setInput($input);
    $process->run();

    return $process;
}

/** @param list<string> $arguments */
function acceptance_test_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

/** @return list<array<string, mixed>> */
function acceptance_test_feedback(string $fixture): array
{
    $path = "{$fixture}/.orbit/feedback.jsonl";

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(preg_split('/\R/', trim((string) file_get_contents($path))) ?: [])),
    );
}

function acceptance_test_remove(string $fixture): void
{
    if (str_contains($fixture, '/orbit-acceptance-')) {
        new Process(['rm', '-rf', $fixture])->run();
    }
}
