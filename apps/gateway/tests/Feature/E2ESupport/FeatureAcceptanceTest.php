<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('blocks route when the active slice graph is invalid before emitting JSON', function (): void {
    $fixture = acceptance_test_workspace('slice-route-invalid', 'README.md');
    if (! is_dir($fixture.'/.orbit/slices')) {
        mkdir($fixture.'/.orbit/slices', recursive: true);
    }
    file_put_contents(
        $fixture.'/.orbit/slices/01-one.md',
        acceptance_slice_packet('01-one', 'none'),
    );
    file_put_contents(
        $fixture.'/.orbit/loop.md',
        "# Orbit Feature Loop\n\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | building | none |\n\n## Status\n\n- State: frame\n- Blocker: none\n",
    );
    try {
        $process = acceptance_test_run($fixture, ['route']);
        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toBe('')
            ->and($process->getErrorOutput())
            ->toContain('building');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('keeps the valid sliced route JSON shape unchanged', function (): void {
    $fixture = acceptance_test_workspace('slice-route-valid', 'README.md');
    if (! is_dir($fixture.'/.orbit/slices')) {
        mkdir($fixture.'/.orbit/slices', recursive: true);
    }
    file_put_contents(
        $fixture.'/.orbit/slices/01-one.md',
        acceptance_slice_packet('01-one', 'none'),
    );
    file_put_contents(
        $fixture.'/.orbit/loop.md',
        "# Orbit Feature Loop\n\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | ready | none |\n\n## Status\n\n- State: frame\n- Blocker: none\n",
    );
    try {
        $process = acceptance_test_run($fixture, ['route']);
        $payload = json_decode($process->getOutput(), true);
        expect($process->getExitCode())
            ->toBe(0)
            ->and(array_keys($payload))
            ->toBe(['candidate', 'base', 'base_tip', 'merge_base', 'changed_files', 'venue', 'venues'])
            ->and($payload)
            ->not->toHaveKey('slice');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires an active Slices index before deriving route JSON', function (): void {
    $fixture = acceptance_test_workspace('slice-route-missing', 'README.md');
    file_put_contents(
        $fixture.'/.orbit/loop.md',
        "# Orbit Feature Loop\n\n## Status\n\n- State: frame\n- Blocker: none\n",
    );

    try {
        $process = acceptance_test_run($fixture, ['route']);
        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toBe('')
            ->and($process->getErrorOutput())
            ->toContain('Slices');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('routes a fresh FRAME loop with a packet authored from the templates', function (): void {
    $fixture = acceptance_test_workspace('slice-route-fresh-frame', 'README.md');
    if (! is_dir($fixture.'/.orbit/slices')) {
        mkdir($fixture.'/.orbit/slices', recursive: true);
    }

    $loop = str_replace(
        [
            '<one verifiable outcome>',
            '- Owned:',
            '- Constraints:',
            '- Out of scope:',
            '- Session:',
            '- Worktree:',
            '- Branch:',
        ],
        [
            'route a fresh framed slice',
            '- Owned: route contract',
            '- Constraints: no manual E2E',
            '- Out of scope: archive work',
            '- Session: fresh-frame',
            "- Worktree: {$fixture}",
            '- Branch: feature',
        ],
        (string) file_get_contents(repo_path('LOOP.md.example')),
    );
    $packet = str_replace(
        [
            '01-example',
            '<one observable vertical increment>',
            '- Included:',
            '- Excluded:',
            '- Decisions:',
            '- Product docs:',
            '- Focused:',
        ],
        [
            '01-fresh-route',
            'route a fresh framed slice',
            '- Included: route contract',
            '- Excluded: archive work',
            '- Decisions: lifecycle contract',
            '- Product docs: feature lifecycle',
            '- Focused: acceptance route tests',
        ],
        (string) file_get_contents(repo_path('SLICE.md.example')),
    );
    file_put_contents($fixture.'/.orbit/loop.md', str_replace(
        '.orbit/slices/01-example.md',
        '.orbit/slices/01-fresh-route.md',
        $loop,
    ));
    unlink($fixture.'/.orbit/slices/01-one.md');
    file_put_contents($fixture.'/.orbit/slices/01-fresh-route.md', $packet);

    try {
        $process = acceptance_test_run($fixture, ['route']);
        $payload = json_decode($process->getOutput(), true);
        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(array_keys($payload))
            ->toBe(['candidate', 'base', 'base_tip', 'merge_base', 'changed_files', 'venue', 'venues'])
            ->and($payload)
            ->not->toHaveKey('slice');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects sliced route packets with missing or unknown Status.State', function (string $status): void {
    $fixture = acceptance_test_workspace('slice-route-status', 'README.md');
    if (! is_dir($fixture.'/.orbit/slices')) {
        mkdir($fixture.'/.orbit/slices', recursive: true);
    }
    file_put_contents(
        $fixture.'/.orbit/slices/01-one.md',
        acceptance_slice_packet('01-one', 'none'),
    );
    file_put_contents(
        $fixture.'/.orbit/loop.md',
        "# Orbit Feature Loop\n\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | ready | none |\n\n## Status\n\n{$status}",
    );
    try {
        $process = acceptance_test_run($fixture, ['route']);
        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toBe('')
            ->and($process->getErrorOutput())
            ->toContain('known Status.State');
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'missing' => '- Blocker: none',
    'unknown' => '- State: mystery\n- Blocker: none',
]);

it('validates building and all-complete sliced route phases before JSON', function (
    string $sliceState,
    string $phase,
): void {
    $fixture = acceptance_test_workspace('slice-route-phase', 'README.md');
    if (! is_dir($fixture.'/.orbit/slices')) {
        mkdir($fixture.'/.orbit/slices', recursive: true);
    }
    file_put_contents($fixture.'/.orbit/slices/01-one.md', acceptance_slice_packet('01-one', 'none'));
    file_put_contents(
        $fixture.'/.orbit/loop.md',
        "# Orbit Feature Loop\n\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | {$sliceState} | none |\n\n## Status\n\n- State: {$phase}\n- Blocker: none\n",
    );

    try {
        $process = acceptance_test_run($fixture, ['route']);
        $payload = json_decode($process->getOutput(), true);
        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(array_keys($payload))
            ->toBe(['candidate', 'base', 'base_tip', 'merge_base', 'changed_files', 'venue', 'venues'])
            ->and($payload)
            ->not->toHaveKey('slice');
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'building' => ['building', 'build'],
    'all complete' => ['complete', 'build'],
]);

it('shows global help without requiring a loop packet', function (string $argument): void {
    $workspace = sys_get_temp_dir().'/orbit-acceptance-help-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);

    try {
        $process = new Process([repo_path('bin/orbit-feature-acceptance'), $argument], $workspace);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))
            ->toBe('Usage: bin/orbit-feature-acceptance route|ready|accept|reprove|invalidate|show [options]')
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

it('derives acceptance venues from changed files', function (array $files, string|array $venue): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    expect(orbitLoopAcceptanceVenues($files))->toBe((array) $venue);
})->with([
    'docs only' => [['apps/docs/content/mission.md'], 'automated'],
    'repository static documentation html' => [
        ['docs/orbit-feature-development-graph.html'],
        'automated',
    ],
    'apps docs resources html stays browser' => [
        ['apps/docs/resources/index.html'],
        'browser',
    ],
    'test only' => [['apps/cli/tests/Feature/Commands/FooTest.php'], 'automated'],
    'repository tooling' => [['bin/orbit-example'], 'automated'],
    'github workflow' => [['.github/workflows/orbit-release.yml'], 'automated'],
    'codex hooks' => [['.codex/hooks.json'], 'automated'],
    'claude settings' => [['.claude/settings.json'], 'automated'],
    'generated session index' => [['.orbit/sessions/index.json'], 'automated'],
    'docs librarian rule' => [['apps/docs/app/Librarian/Rules/SignatureLiveSurfaceRule.php'], 'automated'],
    'docs librarian command-docs config' => [
        ['apps/docs/config/librarian-command-docs/entities/node.php'],
        'automated',
    ],
    'docs generated catalog' => [['apps/docs/content/generated/command-catalog.json'], 'automated'],
    'docs automation surfaces together' => [
        [
            'apps/docs/app/Librarian/Rules/SignatureLiveSurfaceRule.php',
            'apps/docs/config/librarian-command-docs/entities/instance.php',
            'apps/docs/content/generated/command-catalog.json',
            'apps/docs/content/domains/3_tool/4_tool-remove/technical/6.2_tool-remove_output-render_json.md',
        ],
        'automated',
    ],
    'php sdk package source stays retained-incus' => [
        ['packages/sdk/src/GatewayConnector.php'],
        'retained-incus',
    ],
    'typescript sdk package source' => [['packages/sdk-typescript/src/client.ts'], 'automated'],
    'shared core runtime source' => [['packages/core/src/Http/JsonEnvelope.php'], 'retained-incus'],
    'cli command' => [['apps/cli/app/Commands/FooCommand.php'], 'retained-incus'],
    'node runtime' => [['apps/gateway/app/Actions/Node/RepairNode.php'], 'retained-incus'],
    'docs non-librarian runtime php' => [['apps/docs/app/Http/Controllers/DocsController.php'], 'retained-incus'],
    'tooling and cli command' => [
        [
            'bin/orbit-example',
            'apps/cli/app/Commands/FooCommand.php',
        ],
        'retained-incus',
    ],
    'mixed sdk venues are sorted exactly' => [
        [
            'packages/sdk/src/GatewayConnector.php',
            'packages/sdk-typescript/src/client.ts',
        ],
        ['retained-incus'],
    ],
    'gateway frontend' => [['apps/gateway/resources/js/app.js'], 'browser'],
    'docs frontend resources stay browser' => [['apps/docs/resources/js/app.js'], 'browser'],
    'docs automation does not downgrade browser resources' => [
        [
            'apps/docs/app/Librarian/Rules/SignatureLiveSurfaceRule.php',
            'apps/docs/resources/js/app.js',
        ],
        'browser',
    ],
    'native mac app' => [['apps/macos/src/main.rs'], 'host-macos'],
    'automation-only may coexist with host-macos' => [
        [
            'apps/docs/content/mission.md',
            'apps/macos/src/main.rs',
        ],
        'host-macos',
    ],
    'automation-only may coexist with browser' => [
        [
            'apps/docs/content/mission.md',
            'apps/gateway/resources/js/app.js',
        ],
        'browser',
    ],
]);

it('fails closed when a candidate diff needs more than one orthogonal non-automated venue', function (
    array $files,
    string $needle,
): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    expect(fn () => orbitLoopAcceptanceVenue($files))->toThrow(RuntimeException::class, $needle);
})->with([
    'retained+browser' => [
        [
            'apps/cli/app/Commands/FooCommand.php',
            'apps/gateway/resources/js/app.js',
        ],
        'orthogonal',
    ],
    'retained+host-macos' => [
        [
            'apps/cli/app/Commands/FooCommand.php',
            'apps/macos/src/main.rs',
        ],
        'more than one orthogonal non-automated acceptance venue',
    ],
    'browser+host-macos' => [
        [
            'apps/gateway/resources/js/app.js',
            'apps/macos/src/main.rs',
        ],
        'browser, host-macos',
    ],
    'triple + automation-only' => [
        [
            'apps/cli/app/Commands/FooCommand.php',
            'apps/gateway/resources/js/app.js',
            'apps/macos/src/main.rs',
            'apps/docs/content/mission.md',
        ],
        'browser, host-macos, retained-incus',
    ],
]);

it('routes proof venue from the exact candidate diff without a loop packet', function (): void {
    $fixture = acceptance_test_workspace(
        'route-typescript-sdk-package',
        'packages/sdk-typescript/src/client.ts',
    );

    try {
        $candidate = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        $baseTip = acceptance_test_git($fixture, ['rev-parse', 'main']);
        $mergeBase = acceptance_test_git($fixture, ['merge-base', 'main', 'HEAD']);
        $loopBefore = is_file("{$fixture}/.orbit/loop.md")
            ? (string) file_get_contents("{$fixture}/.orbit/loop.md")
            : null;

        $process = new Process([
            repo_path('bin/orbit-feature-acceptance'),
            'route',
            "--cwd={$fixture}",
        ], $fixture);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toBeEmpty();

        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)
            ->toMatchArray([
                'candidate' => $candidate,
                'base' => 'main',
                'base_tip' => $baseTip,
                'merge_base' => $mergeBase,
                'venue' => 'automated',
            ])
            ->and($payload['changed_files'])
            ->toBe(['packages/sdk-typescript/src/client.ts'])
            ->and(array_keys($payload))
            ->toBe(['candidate', 'base', 'base_tip', 'merge_base', 'changed_files', 'venue', 'venues']);

        $loopAfter = is_file("{$fixture}/.orbit/loop.md")
            ? (string) file_get_contents("{$fixture}/.orbit/loop.md")
            : null;

        expect($loopAfter)->toBe($loopBefore);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('uses the same exact diff derivation for route and ready', function (): void {
    $fixture = acceptance_test_workspace(
        'route-ready-agreement',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        $route = new Process([
            repo_path('bin/orbit-feature-acceptance'),
            'route',
            "--cwd={$fixture}",
        ], $fixture);
        $route->run();

        expect($route->getExitCode())->toBe(0, $route->getErrorOutput());

        $routePayload = json_decode($route->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );

        $ready = acceptance_test_run($fixture, ['ready']);

        expect($ready->getExitCode())
            ->toBe(0, $ready->getErrorOutput())
            ->and($ready->getOutput())
            ->toContain('ACCEPTANCE READY venue='.$routePayload['venue'].' actor=automated')
            ->and($routePayload)
            ->toMatchArray([
                'venue' => 'retained-incus',
                'changed_files' => ['apps/cli/app/Commands/FooCommand.php'],
            ])
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance venue: retained-incus');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('fails closed when route cannot derive the exact proof route', function (): void {
    $fixture = acceptance_test_workspace(
        'route-missing-base',
        'packages/sdk/src/GatewayConnector.php',
    );

    try {
        acceptance_test_git($fixture, ['branch', '-D', 'main']);

        $process = new Process([
            repo_path('bin/orbit-feature-acceptance'),
            'route',
            "--cwd={$fixture}",
        ], $fixture);
        $process->run();

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('unable to derive exact proof route')
            ->and($process->getOutput())
            ->toBeEmpty();
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires exact environment=live when a non-automated receipt claims a live or production surface', function (
    string $runtime,
    bool $accepted,
    string $needle,
): void {
    $fixture = acceptance_test_workspace(
        'live-environment-receipt-'.bin2hex(random_bytes(3)),
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
        acceptance_test_write_runtime_row(
            $fixture,
            str_replace('<TIP>', $tip, $runtime),
        );

        $ready = acceptance_test_run($fixture, ['ready']);

        if ($accepted) {
            expect($ready->getExitCode())
                ->toBe(0, $ready->getErrorOutput())
                ->and($ready->getOutput())
                ->toContain('ACCEPTANCE READY venue=retained-incus actor=automated');
        } else {
            expect($ready->getExitCode())
                ->toBe(2)
                ->and($ready->getErrorOutput())
                ->toContain($needle)
                ->toContain('FIX -> BUILD -> PROVE');
        }
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'ordinary retained topology may stay dev-fixture' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        '',
    ],
    'exact live environment accepts live surface target' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=live; target=live production gateway; expected=exit 0; observed=exit 0 on live topology; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        '',
    ],
    'dev-fixture cannot claim production target' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=production gateway; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'environment=live',
    ],
    'dev-fixture cannot claim live command surface' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=live-1; expected=healthy; observed=healthy; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'environment=live',
    ],
    'environment production synonym is not exact live' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=production; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'environment=live',
    ],
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

it('refuses ready and accept when the shared proof receipt is missing or stale', function (): void {
    $fixture = acceptance_test_workspace('acceptance-receipt-required', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );
        foreach (glob("{$fixture}/.orbit/quality-gates/quality-check-*.json") ?: [] as $artifact) {
            unlink($artifact);
        }

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('non-docs diff requires exact `composer quality-check` evidence')
            ->and($accepted->getExitCode())
            ->toBe(2)
            ->and($accepted->getErrorOutput())
            ->toContain('non-docs diff requires exact `composer quality-check` evidence');
    } finally {
        acceptance_test_remove($fixture);
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
                '${1}- runtime: not applicable - repository tooling',
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

it('validates and records an automated candidate in one accept command', function (): void {
    $fixture = acceptance_test_workspace(
        'automated-one-command',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'automated',
        );

        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and($accepted->getOutput())
            ->toContain('ACCEPTED feature=')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: accepted')
            ->toContain('- Acceptance venue: retained-incus')
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment')
            ->toContain('- Accepted feature tip: '.acceptance_test_git($fixture, ['rev-parse', 'HEAD']));
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('keeps delayed human acceptance as a ready arm then later accept', function (): void {
    $fixture = acceptance_test_workspace(
        'human-delayed-arm',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=required',
            venue: 'retained-incus',
        );

        $ready = acceptance_test_run($fixture, ['ready']);
        expect($ready->getExitCode())->toBe(0, $ready->getErrorOutput());
        expect((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: accept')
            ->toContain('- Acceptance: pending');

        $accepted = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#delayed-accept'],
            "Looks correct after the delay.\n",
        );

        expect($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: accepted')
            ->toContain('- Acceptance: accepted - user @ codex://threads/example#delayed-accept');
    } finally {
        acceptance_test_remove($fixture);
    }
});

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
                '${1}- runtime: not applicable - skipped',
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

it('keeps LOOP.md.example free of compact proof path citations', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    $content = (string) file_get_contents(repo_path('LOOP.md.example'));

    // Fresh automated loops copy this template; any compact proof marker would
    // force a dummy evidence file before operators write a real receipt.
    expect(orbitLoopProofReferences($content))
        ->toBeEmpty()
        ->and($content)
        ->not->toContain('evidence=\`')
        ->not->toContain('.orbit/evidence/')
        ->not->toContain('.orbit/quality-gates/')
        ->not->toContain('.orbit/release-evidence/')
        ->not->toContain('target=<target>|command=<cmd>')
        ->not->toContain('exactly one of target= or command=')
        ->not->toContain(
            'worktree evidence tree',
        );
});

it('extracts compact release-evidence citations without expanding runtime receipt roots', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    $fixture = acceptance_test_workspace(
        'release-evidence-root-separation',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        $releasePath = "{$fixture}/.orbit/release-evidence/2026-08-05-slice/proof.txt";
        mkdir(dirname($releasePath), recursive: true);
        file_put_contents($releasePath, "release packaging proof\n");

        $references = orbitLoopProofReferences(
            "- Release evidence: `.orbit/release-evidence/2026-08-05-slice/proof.txt`\n",
        );
        $runtimeProblem = orbitLoopRuntimeProofEvidenceProblem(
            $fixture,
            '`.orbit/release-evidence/2026-08-05-slice/proof.txt`',
        );

        expect($references)
            ->toBe(['.orbit/release-evidence/2026-08-05-slice/proof.txt'])
            ->and($runtimeProblem)
            ->not
            ->toBeNull()
            ->toContain('.orbit/evidence/')
            ->toContain('.orbit/quality-gates/');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects deferred or failed final-hop runtime claims and accepts structured completed proof', function (
    string $runtimeDetail,
    bool $shouldPass,
    ?string $errorNeedle,
    bool $seedEvidence = true,
    ?callable $mutateFixture = null,
): void {
    $fixture = acceptance_test_workspace(
        'runtime-final-hop-'.substr(md5($runtimeDetail.$errorNeedle), 0, 10),
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
            preg_replace(
                '/^(\s*)- runtime: .+$/m',
                '${1}- runtime: '.$runtime,
                $loop,
                1,
            ) ?? $loop,
        );

        if ($seedEvidence) {
            acceptance_test_seed_runtime_evidence($fixture);
        }

        if ($mutateFixture !== null) {
            $mutateFixture($fixture);
        }

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
    'final hop excluded from this run' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop excluded from this run; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'live candidate verification is still required' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=live candidate verification is still required; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'will confirm post merge' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=will confirm post merge; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    're-proof after landing' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=re-proof after landing; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'final hop skipped' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop skipped; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'final hop not reached pending' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop not reached, pending; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'we defer the last hop' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=we defer the last hop; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed closure is a deferral' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=closure is a deferral; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed will re-run post land' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=will re-run post land; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed re-proof after merge' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=re-proof after merge; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed final hop not completed' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop not completed; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed final hop incomplete' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop incomplete; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed to be run later' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=to be run later; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed live verification outstanding' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=live verification outstanding; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed we excluded the decisive hop' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=we excluded the decisive hop; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
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
    'valid without failures narrative' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=completed without failures and 0 pending; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid failure modes absent narrative' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=failure modes absent; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid count narrative 0 failed' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=44 passed, 0 failed; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid count narrative 3 skipped' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=44 passed, 3 skipped; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid count narrative 0 skipped' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=0 skipped; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'observed hop skipped' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=hop skipped; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed live verification skipped' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=live verification skipped; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'observed skipped the live hop' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=skipped the live hop; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'valid queue drained nothing pending' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=queue drained, nothing pending; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid no failed hops' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=no failed hops; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid deferred-queue environment compound' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=deferred-queue fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid no incomplete migrations' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=no incomplete migrations; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid excluding vendor dirs' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0 excluding vendor dirs; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'valid later kernel version' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0 on later kernel 6.8; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'protected words in target field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=deferred-queue worker; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'protected words in environment field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=post-merge-smoke fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'protected words in command field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; command=bin/orbit-gateway-pest --filter=rejects deferred final-hop; expected=pass; observed=pass; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'protected words in evidence path name' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/post-merge-smoke.txt`',
        true,
        null,
        true,
        static function (string $fixture): void {
            acceptance_test_seed_runtime_evidence($fixture, '.orbit/evidence/post-merge-smoke.txt');
        },
    ],
    'negative expected failure remains valid' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=expected failure observed then repaired; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'deferral parked in note field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; note=post-LAND re-proof follows; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'deferral parked in expected field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=live hop deferred to post-merge; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'deferral parked in environment field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=deferred to post-merge; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'missing evidence file' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'existing regular file',
        false,
        static function (string $fixture): void {
            $path = "{$fixture}/.orbit/evidence/runtime-proof.txt";

            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        },
    ],
    'candidate mismatch' => [
        'passed - candidate=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'candidate=',
    ],
    'non-40-hex candidate' => [
        'passed - candidate=not-a-forty-character-hex-digest; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        '40-character',
    ],
    'venue mismatch' => [
        'passed - candidate=<TIP>; venue=browser; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'venue=',
    ],
    'result not passed' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=failed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'result=',
    ],
    'missing required field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'missing observed=',
    ],
    'both target and command' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; command=orbit doctor; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'exactly one of target= or command=',
    ],
    'neither target nor command' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'exactly one of target= or command=',
    ],
    'empty target' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'exactly one of target= or command=',
    ],
    'empty command' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; command=; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'exactly one of target= or command=',
    ],
    'malformed fields' => [
        'passed - candidate=<TIP>; venue=retained-incus; not-a-field; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'duplicate fields' => [
        'passed - candidate=<TIP>; venue=retained-incus; venue=browser; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'unknown extra field rejected' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; note=forward-compat; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'unknown injected key rejected as closed schema' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; injected=1; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'raw semicolon in observed rejected as structure' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; not valid; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'structured runtime receipt',
    ],
    'evidence outside roots' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/sessions/not-allowed.txt`',
        false,
        'evidence=',
        false,
    ],
    'release-evidence root remains outside runtime receipt roots' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/release-evidence/2026-08-05-slice/proof.txt`',
        false,
        'evidence=',
        false,
        static function (string $fixture): void {
            $path = "{$fixture}/.orbit/release-evidence/2026-08-05-slice/proof.txt";
            mkdir(dirname($path), recursive: true);
            file_put_contents($path, "release packaging proof\n");
        },
    ],
    'evidence traversal' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/../sessions/escape.txt`',
        false,
        'evidence=',
        false,
    ],
    'evidence direct symlink' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'symlink',
        false,
        static function (string $fixture): void {
            if (! is_dir("{$fixture}/.orbit/evidence")) {
                mkdir("{$fixture}/.orbit/evidence", recursive: true);
            }

            // Keep the real target under ignored `.orbit/` so clean-worktree
            // checks do not fire before runtime evidence validation.
            file_put_contents("{$fixture}/.orbit/outside-target.txt", "outside\n");
            $link = "{$fixture}/.orbit/evidence/runtime-proof.txt";

            if (file_exists($link) || is_link($link)) {
                unlink($link);
            }

            symlink("{$fixture}/.orbit/outside-target.txt", $link);
        },
    ],
    'evidence symlinked parent' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/nested/runtime-proof.txt`',
        false,
        'symlink',
        false,
        static function (string $fixture): void {
            $store = "{$fixture}/.orbit/real-evidence-store";

            if (! is_dir($store)) {
                mkdir($store, recursive: true);
            }

            file_put_contents("{$store}/runtime-proof.txt", "nested\n");
            $evidenceLink = "{$fixture}/.orbit/evidence";

            if (is_link($evidenceLink) || is_file($evidenceLink)) {
                unlink($evidenceLink);
            } elseif (is_dir($evidenceLink)) {
                acceptance_test_remove_empty_dir($evidenceLink);
            }

            symlink($store, $evidenceLink);
        },
    ],
]);

it('keeps a failed terminal runtime proof in PROVE and completes FIX -> BUILD -> PROVE before acceptance', function (): void {
    $fixture = acceptance_test_workspace(
        'prove-repair-transition',
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );
        $originalTip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        acceptance_test_write_runtime_row(
            $fixture,
            'passed - candidate='
            .$originalTip
            .'; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 1; result=failed; evidence=`.orbit/evidence/runtime-proof.txt`',
        );

        $failedReady = acceptance_test_run($fixture, ['ready']);
        $afterFailedProof = (string) file_get_contents("{$fixture}/.orbit/loop.md");

        expect($failedReady->getExitCode())
            ->toBe(2)
            ->and($failedReady->getErrorOutput())
            ->toContain('result=')
            ->toContain('FIX -> BUILD -> PROVE')
            ->and($afterFailedProof)
            ->toContain('- State: prove')
            ->toContain('- Acceptance: pending')
            ->toContain('- Accepted feature tip: none')
            ->toContain('- Accepted main tip: none')
            ->toContain('- Reviewed feature tip: '.$originalTip);

        file_put_contents("{$fixture}/apps/cli/app/Commands/FooCommand.php", "repaired candidate\n");
        acceptance_test_git($fixture, ['add', 'apps/cli/app/Commands/FooCommand.php']);
        acceptance_test_git($fixture, ['commit', '-m', 'Repair candidate after failed terminal proof']);
        $repairedTip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);

        $staleReady = acceptance_test_run($fixture, ['ready']);

        expect($staleReady->getExitCode())
            ->toBe(2)
            ->and($staleReady->getErrorOutput())
            ->toContain('reviewed feature tip does not equal candidate HEAD')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: prove')
            ->toContain('- Acceptance: pending')
            ->toContain('- Reviewed feature tip: '.$originalTip);

        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $loop =
            preg_replace(
                '/^(\s*)- Review: .+$/m',
                '${1}- Review: passed - reviewer - human-judgment=not-required',
                $loop,
                1,
            ) ?? $loop;
        $loop =
            preg_replace(
                '/^(\s*)- Reviewed feature tip: .+$/m',
                '${1}- Reviewed feature tip: '.$repairedTip,
                $loop,
                1,
            ) ?? $loop;
        file_put_contents("{$fixture}/.orbit/loop.md", $loop);
        acceptance_test_write_runtime_row(
            $fixture,
            acceptance_test_structured_runtime($fixture, $repairedTip),
        );
        acceptance_test_write_gate_artifact($fixture);

        $ready = acceptance_test_run($fixture, ['ready']);
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);
        $mainTip = acceptance_test_git($fixture, ['rev-parse', 'main']);

        expect($ready->getExitCode())
            ->toBe(0, $ready->getErrorOutput())
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: accepted')
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment')
            ->toContain('- Accepted feature tip: '.$repairedTip)
            ->toContain('- Accepted main tip: '.$mainTip)
            ->toContain('- Reviewed feature tip: '.$repairedTip)
            ->toContain('| `.orbit/slices/01-one.md` | complete | '.$originalTip.' |');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('disarms acceptance when terminal runtime proof becomes unresolved after arming or accepting', function (
    string $state,
    bool $recordAcceptance,
): void {
    $fixture = acceptance_test_workspace(
        'disarm-runtime-'.$state,
        'apps/cli/app/Commands/FooCommand.php',
    );

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: $state === 'accepted' ? 'accept' : $state,
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );

        if ($recordAcceptance) {
            $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);
            expect($accepted->getExitCode())->toBe(0, $accepted->getErrorOutput());
            expect((string) file_get_contents("{$fixture}/.orbit/loop.md"))
                ->toContain('- State: accepted');
        } elseif ($state === 'accept') {
            $ready = acceptance_test_run($fixture, ['ready']);
            expect($ready->getExitCode())->toBe(0, $ready->getErrorOutput());
            expect((string) file_get_contents("{$fixture}/.orbit/loop.md"))
                ->toContain('- State: accept')
                ->toContain('- Acceptance: pending');
        }

        $tip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        acceptance_test_write_runtime_row(
            $fixture,
            'passed - candidate='
            .$tip
            .'; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 1; result=failed; evidence=`.orbit/evidence/runtime-proof.txt`',
        );

        $blocked = acceptance_test_run($fixture, ['ready']);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('result=')
            ->toContain('FIX -> BUILD -> PROVE')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: prove')
            ->toContain('- Acceptance: pending')
            ->toContain('- Accepted feature tip: none')
            ->toContain('- Accepted main tip: none')
            ->toContain('- Reviewed feature tip: '.$tip);
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'armed accept' => ['accept', false],
    'already accepted' => ['accepted', true],
]);

it('does not normalize acceptance through a symlinked .orbit root', function (): void {
    $fixture = acceptance_test_workspace(
        'normalize-orbit-root-symlink',
        'apps/cli/app/Commands/FooCommand.php',
    );
    $outside = sys_get_temp_dir().'/orbit-orbit-root-'.bin2hex(random_bytes(6));

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );
        $armed = acceptance_test_run($fixture, ['ready']);
        expect($armed->getExitCode())->toBe(0, $armed->getErrorOutput());
        expect((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: accept');

        $tip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        acceptance_test_write_runtime_row(
            $fixture,
            'passed - candidate='
            .$tip
            .'; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 1; result=failed; evidence=`.orbit/evidence/runtime-proof.txt`',
        );

        acceptance_test_replace_orbit_root_with_symlink($fixture, $outside);
        // Directory-only tracked ignore (`.orbit/`) does not cover a `.orbit`
        // symlink. Local exclude lets ready reach runtime refusal without
        // changing tracked fixture content or the shared workspace contract.
        file_put_contents("{$fixture}/.git/info/exclude", ".orbit\n", FILE_APPEND);
        $outsideLoopPath = "{$outside}/loop.md";
        $beforeOutside = (string) file_get_contents($outsideLoopPath);

        expect($beforeOutside)
            ->toContain('- State: accept')
            ->toContain('result=failed');

        $blocked = acceptance_test_run($fixture, ['ready']);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('SECRET_SCAN: BLOCKED slice packets rule=unsafe-slice-contract')
            ->and((string) file_get_contents($outsideLoopPath))
            ->toBe($beforeOutside)
            ->toContain('- State: accept')
            ->not->toContain('- State: prove');
    } finally {
        acceptance_test_cleanup_orbit_root_symlink($fixture, $outside);
        acceptance_test_remove($fixture);
    }
});

it('rejects runtime evidence when the worktree .orbit root is a symlink', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    $fixture = acceptance_test_workspace(
        'runtime-orbit-root-symlink',
        'apps/cli/app/Commands/FooCommand.php',
    );
    $outside = sys_get_temp_dir().'/orbit-orbit-root-'.bin2hex(random_bytes(6));

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - human-judgment=not-required',
            venue: 'retained-incus',
        );
        $tip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
        acceptance_test_write_runtime_row($fixture, acceptance_test_structured_runtime($fixture, $tip));
        acceptance_test_replace_orbit_root_with_symlink($fixture, $outside);

        $problem = orbitLoopRuntimeProofProblem(
            (string) file_get_contents("{$fixture}/.orbit/loop.md"),
            'retained-incus',
            $tip,
            $fixture,
        );

        expect($problem)
            ->not
            ->toBeNull()
            ->toContain('symlink')
            ->toContain('remain in PROVE');
    } finally {
        acceptance_test_cleanup_orbit_root_symlink($fixture, $outside);
        acceptance_test_remove($fixture);
    }
});

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

it('reports all recorded acceptance venues in show JSON', function (): void {
    $fixture = acceptance_test_workspace('show-plural-venues', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $loopPath = "{$fixture}/.orbit/loop.md";
        $loop = str_replace(
            '- Acceptance venue: retained-incus',
            '- Acceptance venues: browser, host-macos',
            (string) file_get_contents($loopPath),
        );
        file_put_contents($loopPath, $loop);

        $show = acceptance_test_run($fixture, ['show', '--json']);
        $status = json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($show->getExitCode())->toBe(0, $show->getErrorOutput())
            ->and($status['venue'])->toBe('browser, host-macos');
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
            ->toContain('safe Codex or Claude source reference')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($before)
            ->and("{$fixture}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('accepts Claude session source references for user acceptance', function (): void {
    $fixture = acceptance_test_workspace('claude-source', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );

        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=claude://sessions/abc123#acceptance-1'],
            "Looks correct; accept.\n",
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $events = acceptance_test_feedback($fixture);

        expect($loop)
            ->toContain('- Acceptance: accepted - user @ claude://sessions/abc123#acceptance-1')
            ->and($events)
            ->toHaveCount(1)
            ->and($events[0])
            ->toMatchArray([
                'session_ref' => 'claude://sessions/abc123#acceptance-1',
                'surface' => 'acceptance.retained-incus',
            ]);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects Solo source references for user acceptance', function (): void {
    $fixture = acceptance_test_workspace('solo-source', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $before = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=solo://proj/4/scratchpad/example--1'],
            "Accept.\n",
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('safe Codex or Claude source reference')
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
    acceptance_test_write_quality_label_files($workspace);
    acceptance_test_git($workspace, [
        'add',
        'README.md',
        '.gitignore',
        'bin/orbit-quality-subgates.php',
        'bin/quality-check.sh',
    ]);
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
    mkdir("{$workspace}/.orbit/slices", recursive: true);
    file_put_contents(
        "{$workspace}/.orbit/slices/01-one.md",
        acceptance_slice_packet('01-one', 'none'),
    );
    file_put_contents(
        "{$workspace}/.orbit/loop.md",
        "# Orbit Feature Loop\n\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | ready | none |\n\n## Status\n\n- State: frame\n- Blocker: none\n",
    );

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
    acceptance_test_write_quality_label_files($workspace);
    acceptance_test_git($workspace, [
        'add',
        'README.md',
        '.gitignore',
        $sourcePath,
        'bin/orbit-quality-subgates.php',
        'bin/quality-check.sh',
    ]);
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
        $fixture,
        $reviewedTip,
        $venue === 'automated' ? 'retained-incus' : $venue,
    );
    acceptance_test_seed_runtime_evidence($fixture);
    acceptance_test_write_gate_artifact($fixture);
    if (! is_dir("{$fixture}/.orbit/slices")) {
        mkdir("{$fixture}/.orbit/slices", recursive: true);
    }
    file_put_contents(
        "{$fixture}/.orbit/slices/01-one.md",
        acceptance_slice_packet('01-one', 'none'),
    );

    file_put_contents("{$fixture}/.orbit/loop.md", <<<MARKDOWN
        # Orbit Feature Loop

        - Session: feat-fixture
        - Worktree: {$fixture}
        - Branch: feature

        ## Goal

        Acceptance fixture.

        ## Scope

        - Owned: fixture
        - Constraints: no manual E2E
        - Out of scope: none

        ## Slices

        | Slice | State | Checkpoint |
        | --- | --- | --- |
        | `.orbit/slices/01-one.md` | complete | {$reviewedTip} |

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

function acceptance_test_write_quality_label_files(string $workspace): void
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

    if (! is_dir("{$workspace}/bin")) {
        mkdir("{$workspace}/bin", recursive: true);
    }

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

function acceptance_test_write_gate_artifact(string $fixture): void
{
    require_once repo_path('bin/orbit-quality-subgates.php');

    $directory = "{$fixture}/.orbit/quality-gates";

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    foreach (glob("{$directory}/quality-check-*.json") ?: [] as $existing) {
        unlink($existing);
    }

    $commit = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);
    $subgates = [];

    foreach (QUALITY_CHECK_EXPECTED_SUBGATES as $label) {
        $subgates[$label] = 0;
    }

    $payload = [
        'gate' => 'quality-check',
        'producer' => 'quality-check.sh',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'exit_code' => 0,
        'duration_ms' => 10,
        'started_at' => gmdate('c'),
        'ended_at' => gmdate('c'),
        'git' => [
            'branch' => 'feature',
            'commit' => $commit,
            'dirty' => false,
        ],
        'subgates' => $subgates,
    ];
    file_put_contents(
        "{$directory}/quality-check-{$commit}.json",
        json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

function acceptance_test_structured_runtime(
    string $fixture,
    string $candidate,
    string $venue = 'retained-incus',
    string $observed = 'exit 0',
): string {
    return (
        'passed - candidate='
        .$candidate
        .'; venue='
        .$venue
        .'; environment=dev-fixture'
        .'; command=fixture runtime command'
        .'; expected=exit 0'
        .'; observed='
        .$observed
        .'; result=passed'
        .'; evidence=`.orbit/evidence/runtime-proof.txt`'
    );
}

function acceptance_test_seed_runtime_evidence(
    string $fixture,
    string $relative = '.orbit/evidence/runtime-proof.txt',
): void {
    $path = $fixture.'/'.ltrim($relative, '/');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    if (is_link($path) || file_exists($path) && ! is_file($path)) {
        return;
    }

    file_put_contents($path, "runtime proof fixture\n");
}

function acceptance_test_write_runtime_row(string $fixture, string $runtime): void
{
    $loopPath = "{$fixture}/.orbit/loop.md";
    $loop = (string) file_get_contents($loopPath);
    $loop =
        preg_replace(
            '/^(\s*)- runtime: .+$/m',
            '${1}- runtime: '.$runtime,
            $loop,
            1,
        ) ?? $loop;
    file_put_contents($loopPath, $loop);
}

function acceptance_test_replace_orbit_root_with_symlink(string $fixture, string $outside): void
{
    $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
    mkdir("{$outside}/evidence", recursive: true);
    file_put_contents("{$outside}/evidence/runtime-proof.txt", "escaped orbit root\n");
    file_put_contents("{$outside}/loop.md", $loop);

    acceptance_test_unlink_if_present("{$fixture}/.orbit/evidence/runtime-proof.txt");
    acceptance_test_unlink_if_present("{$fixture}/.orbit/loop.md");
    acceptance_test_remove_empty_dir("{$fixture}/.orbit/evidence");
    acceptance_test_remove_empty_dir("{$fixture}/.orbit/quality-gates");
    acceptance_test_remove_empty_dir("{$fixture}/.orbit/slices");
    acceptance_test_remove_empty_dir("{$fixture}/.orbit");
    symlink($outside, "{$fixture}/.orbit");
}

function acceptance_test_cleanup_orbit_root_symlink(string $fixture, string $outside): void
{
    acceptance_test_unlink_if_present("{$fixture}/.orbit");
    acceptance_test_unlink_if_present("{$outside}/evidence/runtime-proof.txt");
    acceptance_test_unlink_if_present("{$outside}/loop.md");
    acceptance_test_remove_empty_dir("{$outside}/evidence");
    acceptance_test_remove_empty_dir($outside);
}

function acceptance_test_unlink_if_present(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
    }
}

function acceptance_test_remove_empty_dir(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }

    $entries = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true),
    ));

    foreach ($entries as $entry) {
        acceptance_test_unlink_if_present($directory.'/'.$entry);
    }

    $remaining = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true),
    ));

    if ($remaining === []) {
        rmdir($directory);
    }
}

function acceptance_slice_packet(string $id, string $depends): string
{
    return (
        "# Orbit Feature Slice\n\n"
        ."- Slice: {$id}\n"
        ."- Depends on: {$depends}\n\n"
        ."## Outcome\n\n"
        ."## Scope\n- Included: route contract\n- Excluded: archive work\n\n"
        ."## Authority\n- Decisions: lifecycle contract\n- Product docs: feature lifecycle\n\n"
        ."## Proof\n- Focused: acceptance route tests\n"
    );
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
