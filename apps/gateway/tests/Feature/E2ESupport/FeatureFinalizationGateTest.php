<?php

declare(strict_types=1);

beforeAll(function (): void {
    require_once dirname(__DIR__, 5).'/bin/orbit-loop-contract.php';
});
it('blocks terminal phases until every indexed slice is complete', function (): void {
    $worktree = sys_get_temp_dir().'/orbit-slice-terminal-'.bin2hex(random_bytes(4));
    if (! is_dir($worktree.'/.orbit/slices')) {
        mkdir($worktree.'/.orbit/slices', recursive: true);
    }
    $packet = compact_feature_loop_packet(state: 'land');
    $packet = preg_replace('/complete \| [0-9a-f]{40}/', 'ready | none', $packet, 1) ?? $packet;
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    file_put_contents($worktree.'/.orbit/slices/01-example.md', finalization_slice_packet('01-example'));

    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, str_repeat('a', 40));
        expect($problems)->not->toBeEmpty()->and($problems[0])->toContain('terminal phase');
    } finally {
        new Symfony\Component\Filesystem\Filesystem()->remove($worktree);
    }
});

it('requires checkpoints for complete slices', function (): void {
    $worktree = sys_get_temp_dir().'/orbit-slice-checkpoint-'.bin2hex(random_bytes(4));
    if (! is_dir($worktree.'/.orbit/slices')) {
        mkdir($worktree.'/.orbit/slices', recursive: true);
    }
    $checkpoint = str_repeat('a', 40);
    $packet =
        preg_replace('/complete \| [0-9a-f]{40}/', 'complete | none', compact_feature_loop_packet(state: 'land'), 1)
        ?? '';
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    file_put_contents($worktree.'/.orbit/slices/01-example.md', finalization_slice_packet('01-example'));

    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, $checkpoint);
        expect($problems)->not->toBeEmpty()->and($problems[0])->toContain('complete slice 01-example');
    } finally {
        new Symfony\Component\Filesystem\Filesystem()->remove($worktree);
    }
});

it('preserves the BUILD graph contract', function (): void {
    $worktree = sys_get_temp_dir().'/orbit-slice-build-'.bin2hex(random_bytes(4));
    mkdir($worktree.'/.orbit/slices', recursive: true);
    $rows =
        "| `.orbit/slices/01-one.md` | complete | none |\n"
        ."| `.orbit/slices/02-two.md` | building | none |\n"
        ."| `.orbit/slices/03-three.md` | ready | none |\n"
        .'| `.orbit/slices/04-four.md` | pending | none |';
    $packet = str_replace(
        '| `.orbit/slices/01-example.md` | ready | none |',
        $rows,
        compact_feature_loop_packet(state: 'build'),
    );
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    foreach (['01-one', '02-two', '03-three', '04-four'] as $id) {
        $depends = ['01-one' => 'none', '02-two' => '01-one', '03-three' => '01-one', '04-four' => '02-two'][$id];
        file_put_contents($worktree.'/.orbit/slices/'.$id.'.md', finalization_slice_packet($id, $depends));
    }
    try {
        expect(orbitLoopSliceFinalizationProblems($packet, $worktree, str_repeat('a', 40)))->toBe([]);
    } finally {
        new Symfony\Component\Filesystem\Filesystem()->remove($worktree);
    }
});

it('rejects the first incomplete slice in every terminal phase', function (string $phase): void {
    $worktree = sys_get_temp_dir().'/orbit-slice-phase-'.bin2hex(random_bytes(4));
    mkdir($worktree.'/.orbit/slices', recursive: true);
    $packet = compact_feature_loop_packet(state: $phase);
    $packet = preg_replace('/complete \| [0-9a-f]{40}/', 'ready | none', $packet, 1) ?? $packet;
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    file_put_contents($worktree.'/.orbit/slices/01-example.md', finalization_slice_packet('01-example'));

    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, str_repeat('a', 40));
        expect($problems[0])->toContain('terminal phase')->toContain('01-example');
    } finally {
        new Symfony\Component\Filesystem\Filesystem()->remove($worktree);
    }
})->with(['prove', 'accept', 'accepted', 'land']);

it('reports malformed checkpoints before later slices', function (): void {
    $worktree = sys_get_temp_dir().'/orbit-malformed-'.bin2hex(random_bytes(3));
    mkdir($worktree.'/.orbit/slices', recursive: true);
    $packet =
        preg_replace(
            '/complete \| [0-9a-f]{40}/',
            'complete | malformed',
            compact_feature_loop_packet(state: 'land'),
            1,
        ) ?? '';
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    file_put_contents($worktree.'/.orbit/slices/01-example.md', finalization_slice_packet('01-example'));
    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, str_repeat('a', 40));
        expect($problems)
            ->not
            ->toBeEmpty()
            ->and($problems[0])
            ->toContain('invalid slice checkpoint')
            ->toContain('01-example');
    } finally {
        new Symfony\Component\Filesystem\Filesystem()->remove($worktree);
    }
});

it('reports non ancestor checkpoints', function (): void {
    [$repo, $worktree, $packet, $tip] = finalization_git_chain_fixture('non-ancestor');
    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, $tip);
        expect($problems[0])->toContain('01-one')->toContain('ancestor');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('reports backwards checkpoint chains deterministically', function (): void {
    [$repo, $worktree, $packet, $tip] = finalization_git_chain_fixture('backwards');
    try {
        $problems = orbitLoopSliceFinalizationProblems($packet, $worktree, $tip);
        expect($problems[0])->toContain('02-two')->toContain('backwards');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('accepts a monotonic multi slice checkpoint chain', function (): void {
    [$repo, $worktree, $packet, $tip] = finalization_git_chain_fixture('valid');
    try {
        expect(orbitLoopSliceFinalizationProblems($packet, $worktree, $tip))->toBe([]);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('does not require slice handoff files', function (): void {
    [$repo, $worktree, $packet, $tip] = finalization_git_chain_fixture('valid');
    try {
        expect(orbitLoopSliceFinalizationProblems($packet, $worktree, $tip))
            ->toBe([])
            ->and(is_dir($worktree.'/.orbit/handoff'))
            ->toBeFalse();
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

use Symfony\Component\Process\Process;

it('documents one compact zero-touch loop contract', function (): void {
    $template = (string) file_get_contents(repo_path('LOOP.md.example'));

    expect($template)
        ->toContain('# Orbit Feature Loop')
        ->toContain('## Goal')
        ->toContain('## Scope')
        ->toContain('## Proof')
        ->toContain('- Blast radius: pending')
        ->toContain('- Review: pending')
        ->toContain('- Reviewed feature tip: none')
        ->toContain('- Acceptance venue: automated')
        ->toContain('- Acceptance: pending')
        ->toContain('- Accepted feature tip: none')
        ->toContain('- Accepted main tip: none')
        ->toContain('## Status')
        ->toContain('- State: frame')
        ->toContain('- Blocker: none')
        ->toContain('## Feedback')
        ->toContain('.orbit/feedback.jsonl')
        ->not->toContain('Allowed states are')
        ->not->toContain('Final Distillation')
        ->not->toContain('Fresh analyzer')
        ->not->toContain('Candidate Signals While Working')
        ->not->toContain('Agent session capture waivers');
});

it('documents clean candidate review escalation and landed archive ordering', function (): void {
    $harness = (string) file_get_contents(repo_path('HARNESS.md'));
    $skill = (string) file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md'));

    expect($harness)
        ->toContain('clean candidate and runtime evidence, and confirms a clean worktree before')
        ->toContain('same general reviewer')
        ->toContain('terminal `PASS` or `FIX`')
        ->toContain('Validate the exact merge mutation with')
        ->toContain('`bin/orbit-feature-finalization-check <exact git command>`')
        ->toContain('After `FINALIZATION: PASS`, execute that exact command separately.')
        ->toContain('After the archive/index commit:')
        ->toContain('Validate each cleanup mutation with')
        ->toContain('After `FINALIZATION: PASS`, execute that exact cleanup command separately.')
        ->toContain('LAND gets minimum venue from the exact accepted candidate contract')
        ->toContain('isolated subprocess; all other finalization checks stay main-owned')
        ->not->toContain('Merge through `bin/orbit-feature-finalization-check git merge <branch>`')->toContain(
            'After merge, keep the accepted feature worktree open',
        )->and(strpos($harness, 'Validate the exact merge mutation with'))->toBeLessThan(strpos(
            $harness,
            'After merge, keep the accepted feature worktree open',
        ))->and($skill)->toContain('then records the clean candidate and runtime evidence')->toContain(
            'same general reviewer',
        )->toContain('terminal PASS or FIX')->toContain('bin/orbit-feature-finalization-check')->toContain(
            '`FINALIZATION: PASS`',
        )->toContain('bin/orbit-session-archive')->toContain('`HARNESS.md` LAND')
        ->not->toContain('Merge through `bin/orbit-feature-finalization-check git merge <branch>`');
});

it('lints the compact loop contract without historical ceremony', function (): void {
    $packetDir = make_finalization_lint_dir(compact_feature_loop_packet());

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('PASS')
            ->not->toContain('Final Distillation');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('lints a completed packet copied from the data-only loop template', function (): void {
    $template = (string) file_get_contents(repo_path('LOOP.md.example'));
    $packet = str_replace(
        [
            '- Session:',
            '- Worktree:',
            '- Branch:',
            '<one verifiable outcome>',
            '- Owned:',
            '- Constraints:',
            '- Out of scope:',
            '- focused: pending',
            '- broader: pending',
            '- Blast radius: pending',
            '- Review: pending',
            '- Reviewed feature tip: none',
            '- Acceptance: pending',
            '- Accepted feature tip: none',
            '- Accepted main tip: none',
            '- State: frame',
        ],
        [
            '- Session: feat-fix-loop-template-placeholders',
            '- Worktree: .worktrees/fix-loop-template-placeholders',
            '- Branch: fix-loop-template-placeholders',
            'Prove a completed generated loop packet passes finalization lint.',
            '- Owned: LOOP.md.example and focused finalization coverage',
            '- Constraints: preserve strict placeholder detection',
            '- Out of scope: acceptance and LAND behavior',
            '- focused: passed - focused finalization test',
            '- broader: passed - composer quality-check',
            '- Blast radius: not-required - local template correction',
            '- Review: passed - general reviewer - human-judgment=not-required',
            '- Reviewed feature tip: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            '- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment',
            '- Accepted feature tip: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            '- Accepted main tip: bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            '- State: accepted',
        ],
        $template,
    );
    $packetDir = make_finalization_lint_dir($packet);
    if (! is_dir("{$packetDir}/.orbit/slices")) {
        mkdir("{$packetDir}/.orbit/slices", recursive: true);
    }
    file_put_contents(
        filename: "{$packetDir}/.orbit/slices/01-example.md",
        data: finalization_slice_packet('01-example'),
    );

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('documents optional compact Scope transition framing without a new row or ceremony', function (): void {
    $template = (string) file_get_contents(repo_path('LOOP.md.example'));
    $harness = (string) file_get_contents(repo_path('HARNESS.md'));
    $skill = (string) file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md'));

    expect($harness)
        ->toContain('primitive=')
        ->toContain('transitions=')
        ->toContain('success:')
        ->toContain('failure:')
        ->toContain('retry:')
        ->toContain('stop-restart:')
        ->toContain('stale:')
        ->toContain('Omit the clause');

    expect($skill)
        ->toContain('primitive=')
        ->toContain('transitions=')
        ->toContain('HARNESS.md');

    expect($template)
        ->toMatch('/^- Owned:\s*$/m')
        ->not->toContain('primitive=')
        ->not->toContain('transitions=')
        ->not->toContain('## Transition Framing')
        ->not->toContain('- Framing:');
});

it('validates optional Scope framing only when the compact markers are present', function (
    string $owned,
    ?string $errorNeedle,
): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    $markdown = compact_feature_loop_packet(owned: $owned);
    $problem = orbitLoopScopeFramingProblem($markdown);

    if ($errorNeedle === null) {
        expect($problem)->toBeNull();
    } else {
        expect($problem)
            ->toBeString()
            ->toContain($errorNeedle);
    }
})->with([
    'ordinary owned without framing' => [
        'loop tooling',
        null,
    ],
    'valid framing with n/a transitions' => [
        'apps/cli progress UX; primitive=progress-tree; transitions=success:exit 0 green tree|failure:nonzero tree|retry:re-run command|stop-restart:n/a|stale:refresh snapshot',
        null,
    ],
    'valid framing reordered transition keys' => [
        'lifecycle feature; primitive=process-start-stop; transitions=stale:refresh|stop-restart:restart unit|retry:retry once|failure:failed state|success:running',
        null,
    ],
    'missing transitions marker' => [
        'apps/cli; primitive=progress-tree',
        'missing transitions=',
    ],
    'missing primitive marker' => [
        'apps/cli; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'missing primitive=',
    ],
    'empty primitive value' => [
        'apps/cli; primitive=; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'primitive=',
    ],
    'missing required transition key' => [
        'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a',
        'missing required key: stale',
    ],
    'unknown transition key' => [
        'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a|boom:x',
        'unknown key: boom',
    ],
    'duplicate transition key' => [
        'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a|success:again',
        'duplicate key: success',
    ],
    'duplicate primitive marker' => [
        'apps/cli; primitive=progress-tree; primitive=other; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'duplicate primitive=',
    ],
    'template placeholder in primitive' => [
        'apps/cli; primitive=<exact primitive>; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'template placeholder',
    ],
    'template placeholder in transitions' => [
        'apps/cli; primitive=progress-tree; transitions=success:<ok>|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'template placeholder',
    ],
    'empty transition value' => [
        'apps/cli; primitive=progress-tree; transitions=success:|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
        'value for success is empty',
    ],
]);

it('parses optional Scope framing only from the Owned row', function (): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    $ordinaryWithMarkerProse = compact_feature_loop_packet(owned: 'loop tooling');
    $ordinaryWithMarkerProse =
        preg_replace(
            '/^- Constraints: .+$/m',
            '- Constraints: docs may mention primitive= and transitions= as format tokens only',
            $ordinaryWithMarkerProse,
        ) ?? $ordinaryWithMarkerProse;
    $ordinaryWithMarkerProse =
        preg_replace(
            '/^- Out of scope: .+$/m',
            '- Out of scope: do not invent a permanent Framing row; leave primitive= off ordinary Owned values',
            $ordinaryWithMarkerProse,
        ) ?? $ordinaryWithMarkerProse;

    expect(orbitLoopScopeFramingProblem($ordinaryWithMarkerProse))->toBeNull();

    $splitAcrossRows = compact_feature_loop_packet(owned: 'apps/cli; primitive=progress-tree');
    $splitAcrossRows =
        preg_replace(
            '/^- Constraints: .+$/m',
            '- Constraints: transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
            $splitAcrossRows,
        ) ?? $splitAcrossRows;

    expect(orbitLoopScopeFramingProblem($splitAcrossRows))
        ->toBeString()
        ->toContain('missing transitions=');

    $completeOnOwnedWithProseElsewhere = compact_feature_loop_packet(
        owned: 'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
    );
    $completeOnOwnedWithProseElsewhere =
        preg_replace(
            '/^- Constraints: .+$/m',
            '- Constraints: other Scope rows may still mention primitive= without becoming the clause source',
            $completeOnOwnedWithProseElsewhere,
        ) ?? $completeOnOwnedWithProseElsewhere;

    expect(orbitLoopScopeFramingProblem($completeOnOwnedWithProseElsewhere))->toBeNull();
});

it('blocks compact finalization lint when optional Scope framing is malformed', function (): void {
    $packetDir = make_finalization_lint_dir(compact_feature_loop_packet(
        owned: 'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad',
    ));

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput().$process->getErrorOutput())
            ->toContain('Scope framing')
            ->toContain('missing required key');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('lints compact finalization when optional Scope framing is complete', function (): void {
    $packetDir = make_finalization_lint_dir(compact_feature_loop_packet(
        owned: 'apps/cli; primitive=progress-tree; transitions=success:ok|failure:bad|retry:again|stop-restart:n/a|stale:n/a',
    ));

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('blocks compact finalization while blast-radius closure is unresolved', function (string $blastRadius): void {
    $packetDir = make_finalization_lint_dir(compact_feature_loop_packet(blastRadius: $blastRadius));

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and(strtolower($process->getOutput().$process->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain(explode(' ', $blastRadius, 2)[0]);
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
})->with([
    'pending' => 'pending',
    'gaps' => 'gaps - shared failure vocabulary still has stale consumers',
]);

it('allows only the exact reviewed and accepted feature and main tips to merge', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks compact finalization on an invalid active slice graph', function (): void {
    $packet =
        compact_feature_loop_packet(includeSlices: false)
        ."\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | building | none |\n| `.orbit/slices/02-two.md` | building | none |\n";
    $packetDir = make_finalization_lint_dir($packet);
    if (! is_dir($packetDir.'/.orbit/slices')) {
        mkdir($packetDir.'/.orbit/slices', recursive: true);
    }
    file_put_contents(
        $packetDir.'/.orbit/slices/01-one.md',
        finalization_slice_packet('01-one'),
    );
    file_put_contents(
        $packetDir.'/.orbit/slices/02-two.md',
        finalization_slice_packet('02-two'),
    );
    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);
        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput().$process->getErrorOutput())
            ->toContain('Slices:');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('requires an active Slices section during compact lint', function (): void {
    $packetDir = make_finalization_lint_dir(compact_feature_loop_packet(), includeSlices: false);

    try {
        $process = run_finalization_check_wrapper($packetDir, ['--lint']);
        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput().$process->getErrorOutput())
            ->toContain('Slices');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('requires complete blast-radius evidence before finalizing a high-authority change', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file(
            $worktree,
            'PRODUCT_DECISIONS.md',
            "# Decisions\n\n2026-07-15: Gateway transport ownership changed.\n",
        );
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            blastRadius: 'not-required - incorrectly treated as local',
        );

        $blocked = run_finalization_gate($repo, 'git merge feature');

        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            blastRadius: "complete - evidence=rg 'transport ownership' apps packages bin; result=all affected surfaces aligned",
        );

        $passed = run_finalization_gate($repo, 'git merge feature');

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and(strtolower($blocked->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('complete')
            ->and($passed->getExitCode())
            ->toBe(0, $passed->getErrorOutput())
            ->and($passed->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('requires blast-radius closure when an authority source is renamed away', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        file_put_contents("{$repo}/PRODUCT_DECISIONS.md", "# Decisions\n");
        run_fixture_command($repo, ['git', 'add', 'PRODUCT_DECISIONS.md']);
        run_fixture_command($repo, ['git', 'commit', '-m', 'Add product decisions']);
        run_fixture_command($worktree, ['git', 'merge', '--no-edit', 'main']);
        mkdir("{$worktree}/docs", recursive: true);
        run_fixture_command($worktree, [
            'git',
            'mv',
            'PRODUCT_DECISIONS.md',
            'docs/decisions-archive.md',
        ]);
        run_fixture_command($worktree, ['git', 'commit', '-m', 'Rename product decisions']);
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            blastRadius: 'not-required - incorrectly inspected only the rename destination',
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('blast radius')
            ->toContain('complete');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks a cwd-changing command from landing the current feature through a non-main checkout', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $process = run_finalization_gate($worktree, "cd {$repo} && git merge feature");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('a non-main checkout may only merge local main into its current feature branch');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('allows a non-main checkout to integrate local main into its current feature branch', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $process = run_finalization_gate($worktree, 'git merge main');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks compact merge when main moved after acceptance', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);
        file_put_contents("{$repo}/AGENTS.md", "# Agents moved\n");
        run_fixture_command($repo, ['git', 'add', 'AGENTS.md']);
        run_fixture_command($repo, ['git', 'commit', '-m', 'Move main']);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('main advanced after acceptance');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('requires advanced main to be integrated before proof and acceptance are repeated', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        file_put_contents("{$repo}/AGENTS.md", "# Agents moved\n");
        run_fixture_command($repo, ['git', 'add', 'AGENTS.md']);
        run_fixture_command($repo, ['git', 'commit', '-m', 'Move main']);
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('integrate main into the feature branch')
            ->toContain('repeat PROVE and ACCEPT');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('requires the reviewer PASS to name the exact feature HEAD', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $reviewedTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    reviewedTip: $reviewedTip,
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('reviewed feature tip does not equal the feature branch tip');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks merge when the non-mutating preview finds a conflict', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(finalization_cleanup_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Feature harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        file_put_contents("{$repo}/HARNESS.md", "# Main harness\n");
        run_fixture_command($repo, ['git', 'add', 'HARNESS.md']);
        run_fixture_command($repo, ['git', 'commit', '-m', 'Conflicting main change']);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('merge preview reported a conflict');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks compact merge when a nonignored untracked file was part of the tested surface', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);
        file_put_contents("{$worktree}/untracked-runtime.txt", "not in accepted HEAD\n");

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('uncommitted or untracked changes')
            ->toContain('untracked-runtime.txt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('routes deleted production files through observable acceptance', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        run_fixture_command($worktree, ['git', 'rm', 'apps/cli/runtime.php']);
        run_fixture_command($worktree, ['git', 'commit', '-m', 'Delete CLI runtime']);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - observable',
                    acceptance: 'accepted - automated - automation-only diff',
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('acceptance venue automated does not satisfy')
            ->toContain('retained-incus');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('derives the minimum acceptance venue from the exact candidate contract', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file(
            $worktree,
            'bin/orbit-loop-contract.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                function orbitLoopAcceptanceVenue(array $changedFiles): string
                {
                    return 'automated';
                }
                PHP,
        );
        commit_finalization_gate_file($worktree, 'apps/cli/runtime.php', "<?php\n\n// Updated runtime.\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('fails closed when candidate changed-file inventory derivation fails', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());
    $fakeBin = sys_get_temp_dir().'/orbit-finalization-git-'.bin2hex(random_bytes(6));

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Changed harness\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        mkdir($fakeBin, recursive: true);
        $gitBinary = trim(new Process(['which', 'git'])->mustRun()->getOutput());
        file_put_contents(
            "{$fakeBin}/git",
            "#!/bin/sh\nif [ \"\$1\" = diff ]; then exit 23; fi\nexec ".escapeshellarg($gitBinary)." \"\$@\"\n",
        );
        chmod("{$fakeBin}/git", 0o755);

        $process = run_finalization_gate(
            $repo,
            'git merge feature',
            ['PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH')],
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('unable to derive candidate changed-file inventory for acceptance venue resolution');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
        new Process(['rm', '-rf', $fakeBin])->run();
    }
});

it('preserves unchanged candidate venue enforcement at the finalization boundary', function (
    array $changedFiles,
    string $minimumVenue,
    string $recordedVenue,
    bool $passes,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        foreach ($changedFiles as $changedFile) {
            commit_finalization_gate_file($worktree, $changedFile, "changed\n");
        }

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $runtime = $recordedVenue === 'automated'
            ? 'not applicable - no runtime proof venue'
            : finalization_structured_runtime($worktree, $featureTip, $recordedVenue);

        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree, $recordedVenue, $runtime);

        $process = run_finalization_gate($repo, 'git merge feature');

        if ($passes) {
            expect($process->getExitCode())
                ->toBe(0, $process->getErrorOutput())
                ->and($process->getOutput())
                ->toContain('FINALIZATION: PASS');

            return;
        }

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain("acceptance venue {$recordedVenue} does not satisfy the diff-routed {$minimumVenue} venue");
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'retained-incus matching' => [['apps/cli/app/Commands/FooCommand.php'], 'retained-incus', 'retained-incus', true],
    'retained-incus rejects automated' => [
        ['apps/cli/app/Commands/FooCommand.php'],
        'retained-incus',
        'automated',
        false,
    ],
    'browser matching' => [['apps/gateway/resources/js/app.js'], 'browser', 'browser', true],
    'browser rejects retained-incus' => [['apps/gateway/resources/js/app.js'], 'browser', 'retained-incus', false],
    'host-macos matching' => [['apps/macos/src/main.rs'], 'host-macos', 'host-macos', true],
    'host-macos rejects browser' => [['apps/macos/src/main.rs'], 'host-macos', 'browser', false],
]);

it('finalizes a candidate with complete multi-venue runtime receipts', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/gateway/resources/js/app.js', "changed\n");
        commit_finalization_gate_file($worktree, 'apps/macos/src/main.rs', "changed\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_multi_venue_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('fails closed when a multi-venue runtime receipt is incomplete or stale', function (string $variant): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/gateway/resources/js/app.js', "changed\n");
        commit_finalization_gate_file($worktree, 'apps/macos/src/main.rs', "changed\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_multi_venue_feature_loop_for_fixture($repo, $worktree, $variant);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())->toBe(2, $process->getOutput().$process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with(['missing receipt', 'stale candidate', 'stale base tip', 'stale merge base', 'duplicate receipt', 'unknown receipt']);

it('fails closed when candidate acceptance venue resolution times out', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file(
            $worktree,
            'bin/orbit-loop-contract.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                usleep(500_000);

                function orbitLoopAcceptanceVenue(array $changedFiles): string
                {
                    return 'automated';
                }
                PHP,
        );
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate(
            $repo,
            'git merge feature',
            ['ORBIT_FINALIZATION_CANDIDATE_VENUE_TIMEOUT_MS' => '50'],
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('candidate acceptance venue')
            ->toContain('timed out');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('fails closed when candidate identity changes during venue resolution', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file(
            $worktree,
            'bin/orbit-loop-contract.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                exec('git commit --allow-empty --quiet -m candidate-identity-drift');

                function orbitLoopAcceptanceVenue(array $changedFiles): string
                {
                    return 'automated';
                }
                PHP,
        );
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('candidate identity changed during acceptance venue resolution');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('reports precise candidate acceptance venue subprocess failures', function (
    string $contract,
    string $errorNeedle,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'bin/orbit-loop-contract.php', $contract);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain(strtolower($errorNeedle));
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'non-zero exit' => [
        <<<'PHP'
            <?php

            fwrite(STDERR, "contract failed\n");
            exit(23);
            PHP,
        'exited with code 23: contract failed',
    ],
    'extra output' => [
        <<<'PHP'
            <?php

            echo "unexpected output\n";

            function orbitLoopAcceptanceVenue(array $changedFiles): string
            {
                return 'automated';
            }
            PHP,
        'returned unexpected extra or multiple output',
    ],
    'unknown venue' => [
        <<<'PHP'
            <?php

            function orbitLoopAcceptanceVenue(array $changedFiles): string
            {
                return 'spaceship';
            }
            PHP,
        'returned unknown venue `spaceship`',
    ],
    'multiple venues' => [
        <<<'PHP'
            <?php

            echo "browser\n";

            function orbitLoopAcceptanceVenue(array $changedFiles): string
            {
                return 'automated';
            }
            PHP,
        'returned unexpected extra or multiple output',
    ],
]);

it('fails closed when the candidate acceptance venue subprocess cannot spawn', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate(
            $repo,
            'git merge feature',
            ['ORBIT_FINALIZATION_PHP_BINARY' => '/missing/orbit-php'],
        );

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('candidate acceptance venue subprocess')
            ->toContain('unable to start');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('requires a regular non-symlink candidate acceptance venue contract', function (bool $replaceWithSymlink): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        run_fixture_command($worktree, ['git', 'rm', 'bin/orbit-loop-contract.php']);

        if ($replaceWithSymlink) {
            if (! is_dir("{$worktree}/bin")) {
                mkdir("{$worktree}/bin", recursive: true);
            }

            symlink('../HARNESS.md', "{$worktree}/bin/orbit-loop-contract.php");
            run_fixture_command($worktree, ['git', 'add', 'bin/orbit-loop-contract.php']);
        }

        run_fixture_command($worktree, ['git', 'commit', '-m', 'Replace candidate contract']);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('regular non-symlink file')
            ->toContain('bin/orbit-loop-contract.php');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'missing contract' => false,
    'symlinked contract' => true,
]);

it('requires runtime proof for retained automated acceptance before finalization', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/cli/runtime.php', "<?php\n\n// Updated runtime.\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - human-judgment=not-required',
                    acceptance: 'accepted - automated - reviewer-confirmed no-human-judgment',
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    venue: 'retained-incus',
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Verification runtime must be passed for acceptance venue retained-incus');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects deferred final-hop runtime claims and accepts structured completed proof at finalization', function (
    string $runtimeDetail,
    bool $shouldPass,
    ?string $errorNeedle,
    bool $seedEvidence = true,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/cli/runtime.php', "<?php\n\n// Updated runtime.\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        $runtime = str_replace('<TIP>', $featureTip, $runtimeDetail);
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - human-judgment=not-required',
                    acceptance: 'accepted - automated - reviewer-confirmed no-human-judgment',
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    venue: 'retained-incus',
                    runtime: $runtime,
                ),
                $worktree,
            ),
        );

        if ($seedEvidence) {
            finalization_seed_runtime_evidence($worktree);
        }

        $process = run_finalization_gate($repo, 'git merge feature');

        if ($shouldPass) {
            expect($process->getExitCode())
                ->toBe(0, $process->getErrorOutput())
                ->and($process->getOutput())
                ->toContain('FINALIZATION: PASS');
        } else {
            expect($process->getExitCode())
                ->toBe(2)
                ->and($process->getErrorOutput())
                ->toContain((string) $errorNeedle)
                ->toContain('remain in PROVE');
        }
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'post-LAND deferral' => [
        'passed - live update failed writing bare /etc/caddy; post-LAND durable re-proof follows',
        false,
        'final hop',
    ],
    'free-form without receipt' => [
        'passed - retained fixture',
        false,
        'structured runtime receipt',
    ],
    'final hop excluded from this run' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=final hop excluded from this run; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'final hop',
    ],
    'protected words in target field' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=deferred-queue worker; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
    'missing evidence file' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        false,
        'existing regular file',
        false,
    ],
    'valid structured receipt' => [
        'passed - candidate=<TIP>; venue=retained-incus; environment=dev-fixture; target=orbit fixture; expected=exit 0; observed=exit 0 with no failures; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`',
        true,
        null,
    ],
]);

it('requires exact automated acceptance provenance', function (
    string $review,
    string $acceptance,
    string $reason,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: $review,
                    acceptance: $acceptance,
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'generic accepted label' => [
        'passed - reviewer example - human-judgment=not-required',
        'accepted - automated',
        'acceptance provenance is invalid',
    ],
    'automation-only claim on reviewer override' => [
        'passed - reviewer example - human-judgment=not-required',
        'accepted - automated - automation-only diff',
        'reviewer-confirmed no-human-judgment',
    ],
    'reviewer claim without reviewer result' => [
        'passed - reviewer example - human-judgment=required',
        'accepted - automated - reviewer-confirmed no-human-judgment',
        'review requires human judgment',
    ],
]);

it('allows ordinary content-preserving merge options', function (string $options): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, "git merge {$options} feature");

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    '--ff',
    '--ff-only',
    '--no-ff',
    '--no-ff --no-edit',
    '--no-ff --edit',
    '--no-ff --no-stat',
]);

it('rejects merge options that can omit or rewrite the accepted candidate', function (
    string $options,
    string $reason,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);

        $process = run_finalization_gate($repo, "git merge {$options} feature");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'squash' => ['--squash', '--squash'],
    'no commit' => ['--no-commit', '--no-commit'],
    'short strategy' => ['-s ours', 'custom merge strategies'],
    'long strategy' => ['--strategy=ours', 'custom merge strategies'],
    'strategy option' => ['-X ours', 'strategy options'],
    'long strategy option' => ['--strategy-option=ours', 'strategy options'],
]);

it('rejects multi-target or chained destructive boundary commands', function (string $command, string $reason): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $otherWorktree = "{$repo}.other";
        run_fixture_command($repo, ['git', 'branch', 'other']);
        run_fixture_command($repo, ['git', 'worktree', 'add', $otherWorktree, 'other']);
        $command = str_replace(
            ['{feature-worktree}', '{other-worktree}'],
            [$worktree, $otherWorktree],
            $command,
        );

        $process = run_finalization_gate($repo, $command);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
        new Process(['rm', '-rf', "{$repo}.other"])->run();
    }
})->with([
    'multiple branch targets' => ['git branch -D feature other', 'exactly one branch target'],
    'multiple worktree targets' => [
        'git worktree remove {feature-worktree} {other-worktree}',
        'exactly one worktree target',
    ],
    'worktree then branch' => [
        'git worktree remove {feature-worktree} && git branch -D feature',
        'exactly one destructive boundary action',
    ],
    'merge then branch' => [
        'git merge feature && git branch -D feature',
        'exactly one destructive boundary action',
    ],
    'two branch commands' => [
        'git branch -D feature && git branch -D other',
        'exactly one destructive boundary action',
    ],
]);

it('rejects automation-only acceptance even for a declarative diff', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - human-judgment=required',
                    acceptance: 'accepted - automated - automation-only diff',
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge --no-ff feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('reviewer-confirmed no-human-judgment');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects an invalid existing feedback stream for automated acceptance', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);
        file_put_contents(
            "{$worktree}/.orbit/feedback.jsonl",
            "{\"schema_version\":1,\"type\":\"unknown\",\"id\":\"bad\",\"recorded_at\":\"2026-07-10T20:00:00Z\"}\n",
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('feedback stream is invalid')
            ->toContain('unknown feedback event type');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects unresolved actionable feedback before merge', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);
        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $event = [
            'schema_version' => 1,
            'type' => 'feedback.recorded',
            'id' => 'feedback-unresolved',
            'recorded_at' => '2026-07-10T20:00:00Z',
            'raw_text' => 'The command still appears frozen.',
            'session_ref' => 'codex://threads/example#feedback',
            'candidate_commit' => $featureTip,
            'surface' => 'cli.progress',
            'actionable' => true,
            'context' => [],
            'evidence' => [],
        ];
        file_put_contents(
            "{$worktree}/.orbit/feedback.jsonl",
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('unresolved actionable feedback: feedback-unresolved');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('keeps finalization quality-check subgates aligned with quality-check.sh CHECK_LABELS', function (): void {
    // Canonical producer: same CHECK_LABELS parser contract as VerificationScriptsTest
    // (helpers live there; parallel workers do not share cross-file test functions).
    $script = (string) file_get_contents(repo_path('bin/quality-check.sh'));
    $producerLabels = finalization_quality_check_script_labels($script, 'CHECK_LABELS');

    $declaration = (string) file_get_contents(repo_path('bin/orbit-quality-subgates.php'));
    expect(preg_match(
        '/const QUALITY_CHECK_EXPECTED_SUBGATES = \[(.*?)\];/s',
        $declaration,
        $matches,
    ))->toBe(1);

    preg_match_all("/'([^']+)'/", $matches[1], $labelMatches);
    $declaredLabels = $labelMatches[1];
    $fixtureLabels = array_keys(finalization_quality_check_subgates());
    $declarationPath = repo_path('bin/orbit-quality-subgates.php');
    $runtime = new Process([
        PHP_BINARY,
        '-r',
        'require '
            .var_export($declarationPath, true)
            .'; echo json_encode(QUALITY_CHECK_EXPECTED_SUBGATES, JSON_THROW_ON_ERROR);',
    ]);
    $runtimeLabels = json_decode($runtime->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    sort($producerLabels);
    sort($declaredLabels);
    sort($fixtureLabels);
    sort($runtimeLabels);

    expect($declaredLabels)
        ->toBe($producerLabels)
        ->and($fixtureLabels)
        ->toBe($producerLabels)
        ->and($runtimeLabels)
        ->toBe($producerLabels)
        ->and($producerLabels)
        ->toContain('sdk_typescript_build')
        ->toContain('sdk_typescript_runtime')
        ->toContain('sdk_typescript_typecheck');
});

it('rejects forged or incomplete reserved quality-check evidence', function (
    string $mutation,
    string $reason,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'bin/example', "#!/usr/bin/env bash\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );
        $artifactPath = latest_finalization_artifact_path($worktree, 'quality-check');
        $artifact = json_decode((string) file_get_contents($artifactPath), true, flags: JSON_THROW_ON_ERROR);

        match ($mutation) {
            'producer' => $artifact['producer'] = 'forged-producer',
            'command' => $artifact['command'] = 'composer quality-check:fix',
            'mode' => $artifact['mode'] = 'fix',
            'missing-subgate' => array_pop($artifact['subgates']),
            'failed-subgate' => $artifact['subgates']['gateway_pest'] = 1,
            'extra-subgate' => $artifact['subgates']['forged_lane'] = 0,
        };
        file_put_contents(
            $artifactPath,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'forged producer' => ['producer', 'producer'],
    'wrong command' => ['command', 'command'],
    'fix mode' => ['mode', 'mode'],
    'missing subgate' => ['missing-subgate', 'exact expected subgate set'],
    'failed subgate' => ['failed-subgate', 'all subgates must be integer exit code 0'],
    'extra subgate' => ['extra-subgate', 'exact expected subgate set'],
]);

it('allows an exact additive candidate quality-check subgate set', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [...array_keys(finalization_quality_check_subgates()), 'candidate_new_subgate'];
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        rewrite_finalization_quality_subgates(
            $worktree,
            array_fill_keys($candidateLabels, 0),
        );

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects a candidate quality-check subgate set that removes a current subgate', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = array_keys(finalization_quality_check_subgates());
        array_pop($candidateLabels);
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        rewrite_finalization_quality_subgates(
            $worktree,
            array_fill_keys($candidateLabels, 0),
        );

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('exact expected subgate set');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects candidate quality-check declarations that disagree', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [...array_keys(finalization_quality_check_subgates()), 'candidate_new_subgate'];
        commit_finalization_candidate_quality_labels(
            $worktree,
            $candidateLabels,
            array_keys(finalization_quality_check_subgates()),
        );
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        rewrite_finalization_quality_subgates(
            $worktree,
            array_fill_keys($candidateLabels, 0),
        );

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('exact expected subgate set');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects an additive candidate declaration when the artifact omits its new subgate', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [...array_keys(finalization_quality_check_subgates()), 'candidate_new_subgate'];
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('exact expected subgate set');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects duplicate labels in an additive candidate quality-check declaration', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [
            ...array_keys(finalization_quality_check_subgates()),
            'candidate_new_subgate',
            'candidate_new_subgate',
        ];
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        rewrite_finalization_quality_subgates(
            $worktree,
            array_fill_keys($candidateLabels, 0),
        );

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('exact expected subgate set');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects a failed additive candidate quality-check subgate', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [...array_keys(finalization_quality_check_subgates()), 'candidate_new_subgate'];
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        $candidateSubgates = array_fill_keys($candidateLabels, 0);
        $candidateSubgates['candidate_new_subgate'] = 1;
        rewrite_finalization_quality_subgates($worktree, $candidateSubgates);

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('all subgates must be integer exit code 0');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects an additive candidate quality-check declaration with executable content', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        $candidateLabels = [...array_keys(finalization_quality_check_subgates()), 'candidate_new_subgate'];
        commit_finalization_candidate_quality_labels($worktree, $candidateLabels);
        file_put_contents(
            "{$worktree}/bin/orbit-quality-subgates.php",
            (string) file_get_contents("{$worktree}/bin/orbit-quality-subgates.php")."\necho 'unexpected';\n",
        );
        run_fixture_command($worktree, ['git', 'add', 'bin/orbit-quality-subgates.php']);
        run_fixture_command($worktree, ['git', 'commit', '-m', 'Add executable declaration content']);
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));
        rewrite_finalization_quality_subgates(
            $worktree,
            array_fill_keys($candidateLabels, 0),
        );

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        write_compact_feature_loop_for_fixture(
            $repo,
            $worktree,
            venue: 'retained-incus',
            runtime: finalization_structured_runtime($worktree, $featureTip),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('exact expected subgate set');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects forged reserved docs-lint evidence', function (string $mutation, string $reason): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
        write_compact_feature_loop_for_fixture($repo, $worktree);
        $artifactPath = latest_finalization_artifact_path($worktree, 'docs-lint');
        $artifact = json_decode((string) file_get_contents($artifactPath), true, flags: JSON_THROW_ON_ERROR);

        match ($mutation) {
            'producer' => $artifact['producer'] = 'forged-producer',
            'command' => $artifact['command'] = 'composer fake-docs-lint',
            'mode' => $artifact['mode'] = 'fix',
            'subgates' => $artifact['subgates'] = ['forged_lane' => 0],
        };
        file_put_contents(
            $artifactPath,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'forged producer' => ['producer', 'producer'],
    'wrong command' => ['command', 'command'],
    'fix mode' => ['mode', 'mode'],
    'nonempty subgates' => ['subgates', 'empty subgate set'],
]);

it('requires a user acceptance event matching the accepted candidate source and surface', function (
    string $candidate,
    string $source,
    string $surface,
    string $reason,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/cli/accepted.php', "<?php\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        $sourceRef = 'codex://threads/019f4bd5-ba0e-7d33-af71-2e8ebc774627#accepted';
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - observable',
                    acceptance: "accepted - user @ {$sourceRef}",
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    venue: 'retained-incus',
                    runtime: finalization_structured_runtime($worktree, $featureTip),
                ),
                $worktree,
            ),
        );
        write_finalization_acceptance_event(
            $worktree,
            $candidate === 'matching' ? $featureTip : str_repeat('c', 40),
            $source === 'matching' ? $sourceRef : 'codex://threads/different#accepted',
            $surface === 'matching' ? 'acceptance.retained-incus' : 'acceptance.browser',
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'candidate mismatch' => ['different', 'matching', 'matching', 'candidate commit'],
    'source mismatch' => ['matching', 'different', 'matching', 'source reference'],
    'surface mismatch' => ['matching', 'matching', 'different', 'acceptance surface'],
]);

it('allows exact user acceptance provenance', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/cli/accepted.php', "<?php\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        $sourceRef = 'codex://threads/019f4bd5-ba0e-7d33-af71-2e8ebc774627#accepted';
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - observable',
                    acceptance: "accepted - user @ {$sourceRef}",
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    venue: 'retained-incus',
                    runtime: finalization_structured_runtime($worktree, $featureTip),
                ),
                $worktree,
            ),
        );
        write_finalization_acceptance_event(
            $worktree,
            $featureTip,
            $sourceRef,
            'acceptance.retained-incus',
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('FINALIZATION: PASS');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects unnecessary user acceptance at finalization', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'apps/cli/accepted.php', "<?php\n");
        write_finalization_gate_artifact($worktree, 'quality-check', 0, gmdate('c'));

        $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
        $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
        file_put_contents(
            "{$worktree}/.orbit/loop.md",
            finalization_bind_compact_worktree(
                compact_feature_loop_packet(
                    review: 'passed - reviewer example - human-judgment=not-required',
                    acceptance: 'accepted - user @ codex://threads/example#unnecessary',
                    featureTip: $featureTip,
                    mainTip: $mainTip,
                    venue: 'retained-incus',
                    runtime: finalization_structured_runtime($worktree, $featureTip),
                ),
                $worktree,
            ),
        );

        $process = run_finalization_gate($repo, 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('human acceptance is unnecessary because review records no human judgment');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('classifies and blocks merge operands that are not one exact linked local branch', function (
    string $command,
    string $reason,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    try {
        commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
        run_fixture_command($repo, ['git', 'branch', 'feature-two']);
        run_fixture_command($repo, ['git', 'update-ref', 'refs/remotes/origin/feature', 'refs/heads/feature']);
        $featureTip = trim(new Process(['git', 'rev-parse', 'feature'], $repo)->mustRun()->getOutput());
        $command = str_replace(['{feature-tip}', '{repo}'], [$featureTip, $repo], $command);

        $process = run_finalization_gate($repo, $command);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason);
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'no merge head' => ['git merge --no-ff', 'exactly one merge head'],
    'raw commit id' => ['git merge {feature-tip}', 'linked local branch'],
    'missing local branch' => ['git merge absent-local', 'linked local branch'],
    'remote tracking branch' => ['git merge origin/feature', 'linked local branch'],
    'revision expression' => ['git merge feature~1', 'linked local branch'],
    'multiple merge heads' => ['git merge feature feature-two', 'exactly one merge head'],
    'git directory option with raw commit' => [
        'git -C {repo} merge {feature-tip}',
        'context overrides are not accepted',
    ],
    'git directory context override with branch' => [
        'git -C {repo} merge feature',
        'context overrides are not accepted',
    ],
    'git config option with missing branch' => [
        'git -c advice.detachedHead=false merge absent-local',
        'linked local branch',
    ],
]);

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
            ->toContain('uncommitted or untracked changes');
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
            ->toContain('Fresh analyzer')
            ->toContain('not used - <rationale>');
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

it('accepts a not used fresh analyzer row without a warning', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - harness markdown diff
          - `composer quality-check`: not applicable - docs-only diff
        - Fresh analyzer:
          - not used - compact loop with no escalation trigger
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
            ->not->toContain('Fresh analyzer deferred');
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

it('allows repository tooling php finalization with artifact-backed quality-check and no retained topology proof', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repository tooling has no retained topology target
          - `composer quality-check`: passed - composer quality-check
        - Fresh analyzer:
          - Verdict: pass - no missed signals
        - Accepted durable updates:
          - bin/example.php changed repository tooling.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'bin/example.php',
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
          - apps/macos/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/macos/src/main.rs',
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
          - apps/macos/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/macos/src/main.rs',
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
          - apps/macos/src/main.rs changed native Tauri behavior.
        - Rejected or already-covered signals:
          - None.
        - Deferred follow-ups:
          - None.
        - No-new-signal rationale:
          - None.
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'apps/macos/src/main.rs',
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

it('blocks cleanup before the feature tip has landed on main', function (): void {
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
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('feature tip is not an ancestor of main')
            ->not->toContain('no session archive');
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
    land_finalization_gate_feature($repo);

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
    land_finalization_gate_feature($repo);

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

it('allows cleanup when a valid compact receipt is discoverable under a different basename via compatible slugs', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);

    // Basename slug differs from branch-derived `feature`; receipt metadata must
    // make the archive discoverable without bypassing tip/digest validation.
    $archive = write_compact_finalization_gate_session_archive(
        $repo,
        $worktree,
        'token-transport-contract',
    );
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['slug'] = 'token-transport-contract';
    $receipt['requested_slug'] = 'feature';
    $receipt['compatible_slugs'] = ['feature', 'token-transport-contract'];
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    commit_finalization_session_archive($repo, $archive);

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects a cross-slug compact receipt that mismatches branch tip or digests', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);

    $archive = write_compact_finalization_gate_session_archive(
        $repo,
        $worktree,
        'token-transport-contract',
    );
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['slug'] = 'token-transport-contract';
    $receipt['requested_slug'] = 'feature';
    $receipt['compatible_slugs'] = ['feature', 'token-transport-contract'];
    // Discoverable via compatible_slugs, but digests no longer match archive bytes.
    $receipt['entry_digests']['loop.md'] = str_repeat('0', 64);
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('bin/orbit-session-archive');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects a cross-slug compact receipt with wrong branch tip even when compatible slugs match', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);

    $archive = write_compact_finalization_gate_session_archive(
        $repo,
        $worktree,
        'token-transport-contract',
    );
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['slug'] = 'token-transport-contract';
    $receipt['requested_slug'] = 'feature';
    $receipt['compatible_slugs'] = ['feature', 'token-transport-contract'];
    // Candidate tip no longer equals the landed feature tip.
    $receipt['candidate_commit'] = str_repeat('a', 40);
    $receipt['accepted_feature_tip'] = str_repeat('a', 40);
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('bin/orbit-session-archive');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
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
    land_finalization_gate_feature($repo);
    write_finalization_gate_session_archive(repo: $repo, slug: 'feature');

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('allows cleanup with a valid compact archive receipt and no agent manifests', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    $loopPath = "{$worktree}/.orbit/loop.md";
    file_put_contents(
        $loopPath,
        str_replace('- Branch: feature', '- Branch: `feature`', (string) file_get_contents($loopPath)),
    );
    land_finalization_gate_feature($repo);
    write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('allows cleanup when a compact receipt binds cited nested proof files', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    $proofSource = latest_finalization_artifact_path($worktree, 'docs-lint');
    $proofEntry = 'quality-gates/'.basename($proofSource);
    write_compact_feature_loop_for_fixture($repo, $worktree);
    $loopPath = "{$worktree}/.orbit/loop.md";
    $loop = (string) file_get_contents($loopPath);
    file_put_contents(
        $loopPath,
        str_replace(
            '## Slices',
            "- Broader proof: `.orbit/{$proofEntry}`\n\n## Slices",
            $loop,
        ),
    );
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    mkdir("{$archive}/quality-gates", recursive: true);
    copy($proofSource, "{$archive}/{$proofEntry}");
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['copied_entries'][] = $proofEntry;
    sort($receipt['copied_entries']);
    $receipt['entry_digests']['loop.md'] = hash_file('sha256', "{$archive}/loop.md");
    $receipt['entry_digests'][$proofEntry] = hash_file(
        'sha256',
        "{$archive}/{$proofEntry}",
    );
    ksort($receipt['entry_digests']);
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    commit_finalization_session_archive($repo, $archive);

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('allows cleanup for historical schema-v2 compact receipts that cite release-evidence without retaining it', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    $proofSource = latest_finalization_artifact_path($worktree, 'docs-lint');
    $proofEntry = 'quality-gates/'.basename($proofSource);
    write_compact_feature_loop_for_fixture($repo, $worktree);
    $loopPath = "{$worktree}/.orbit/loop.md";
    $loop = (string) file_get_contents($loopPath);
    file_put_contents(
        $loopPath,
        str_replace(
            '## Slices',
            <<<MARKDOWN
                - Broader proof: `.orbit/{$proofEntry}`
                - Release evidence: `.orbit/release-evidence/2026-08-04-live-candidate/proof.txt`

                ## Slices
                MARKDOWN,
            $loop,
        ),
    );
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    mkdir("{$archive}/quality-gates", recursive: true);
    copy($proofSource, "{$archive}/{$proofEntry}");
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['schema_version'] = 2;
    $receipt['copied_entries'] = ['loop.md', $proofEntry];
    sort($receipt['copied_entries']);
    $receipt['entry_digests'] = [
        'loop.md' => hash_file('sha256', "{$archive}/loop.md"),
        $proofEntry => hash_file('sha256', "{$archive}/{$proofEntry}"),
    ];
    ksort($receipt['entry_digests']);
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    expect($receipt['copied_entries'])
        ->not->toContain('release-evidence/2026-08-04-live-candidate/proof.txt')->and("{$archive}/release-evidence")
        ->not->toBeDirectory();

    commit_finalization_session_archive($repo, $archive);

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects schema-v3 compact receipts that cite release-evidence without binding it', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    $loopPath = "{$worktree}/.orbit/loop.md";
    $loop = (string) file_get_contents($loopPath);
    file_put_contents(
        $loopPath,
        str_replace(
            '## Slices',
            "- Release evidence: `.orbit/release-evidence/2026-08-05-slice/proof.txt`\n\n## Slices",
            $loop,
        ),
    );
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['schema_version'] = 3;
    $receipt['copied_entries'] = ['loop.md'];
    $receipt['entry_digests'] = [
        'loop.md' => hash_file('sha256', "{$archive}/loop.md"),
    ];
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects a compact receipt that binds only a truncated proof citation', function (string $citation): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    $loopPath = "{$worktree}/.orbit/loop.md";
    $loop = (string) file_get_contents($loopPath);
    file_put_contents(
        $loopPath,
        str_replace(
            '## Slices',
            "- Broader proof: `{$citation}`\n\n## Slices",
            $loop,
        ),
    );
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    mkdir("{$archive}/quality-gates", recursive: true);
    file_put_contents("{$archive}/quality-gates/proof", "wrong truncated proof\n");
    $receiptPath = "{$archive}/orbit-session-archive.json";
    $receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
    $receipt['copied_entries'][] = 'quality-gates/proof';
    sort($receipt['copied_entries']);
    $receipt['entry_digests']['loop.md'] = hash_file('sha256', "{$archive}/loop.md");
    $receipt['entry_digests']['quality-gates/proof'] = hash_file(
        'sha256',
        "{$archive}/quality-gates/proof",
    );
    ksort($receipt['entry_digests']);
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'invalid right suffix' => ['.orbit/quality-gates/proof.?json'],
    'invalid left prefix' => ['prefix.orbit/quality-gates/proof'],
]);

it('rejects a malformed compact archive receipt', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    file_put_contents("{$archive}/orbit-session-archive.json", "{}\n");
    mkdir("{$archive}/agent-sessions");
    file_put_contents(
        "{$archive}/agent-sessions/manifest.json",
        json_encode(['schema_version' => 1, 'sessions' => []], JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('rejects compact archive bytes that no longer match the receipt', function (string $entry): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    write_finalization_acceptance_event(
        $worktree,
        trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput()),
        'codex://threads/example#accepted',
        'acceptance.automated',
    );
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature', includeFeedback: true);
    file_put_contents("{$archive}/{$entry}", "tampered\n", FILE_APPEND);

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with(['loop.md', 'feedback.jsonl']);

it('rejects a compact receipt whose branch or candidate identity is not exact', function (
    string $field,
    string $value,
): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    $receipt = json_decode(
        (string) file_get_contents("{$archive}/orbit-session-archive.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $receipt[$field] = $value;
    file_put_contents(
        "{$archive}/orbit-session-archive.json",
        json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
})->with([
    'branch' => ['branch', 'different'],
    'candidate commit' => ['candidate_commit', 'cccccccccccccccccccccccccccccccccccccccc'],
    'accepted feature tip' => ['accepted_feature_tip', 'cccccccccccccccccccccccccccccccccccccccc'],
    'accepted main tip' => ['accepted_main_tip', 'cccccccccccccccccccccccccccccccccccccccc'],
]);

it('rejects compact receipt entry lists that do not match copied archive entries and digests', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet());

    commit_finalization_gate_file($worktree, 'HARNESS.md', "# Compact harness\n");
    write_finalization_gate_artifact($worktree, 'docs-lint', 0, gmdate('c'));
    write_compact_feature_loop_for_fixture($repo, $worktree);
    land_finalization_gate_feature($repo);
    $archive = write_compact_finalization_gate_session_archive($repo, $worktree, 'feature');
    file_put_contents("{$archive}/unexpected.txt", "not receipted\n");

    try {
        $process = run_finalization_gate($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('valid compact receipt');
    } finally {
        remove_finalization_gate_fixture($repo, $worktree);
    }
});

it('blocks cleanup when the matching session archive uses only the branch slug', function (): void {
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
    land_finalization_gate_feature($repo);
    write_finalization_gate_session_archive(repo: $repo, slug: 'feature', timestamped: false);

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('no session archive matching slug `feature` was found')
            ->toContain('bin/orbit-session-archive');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('blocks cleanup when the matching session archive is missing agent session manifests', function (): void {
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
    land_finalization_gate_feature($repo);
    $archiveDir = write_finalization_gate_session_archive(repo: $repo, slug: 'feature');
    unlink("{$archiveDir}/agent-sessions/manifest.json");

    try {
        $process = run_finalization_gate(repo: $repo, command: "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('agent session manifests');
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

it('keeps one compact loop schema without orchestration appendices', function (): void {
    $template = (string) file_get_contents(repo_path('LOOP.md.example'));

    expect($template)
        ->toContain('# Orbit Feature Loop')
        ->toContain('## Goal')
        ->toContain('## Proof')
        ->toContain('## Status')
        ->not->toContain('Appendix')
        ->not->toContain('Parallelization scan')
        ->not->toContain('Final Distillation');
});

it('finalization gate blocks lanes-having packet with zero healthy captures and no waiver', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - test packet for capture health gate
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - test coverage for zero healthy capture block when lanes named and no waiver row

        - Worker: lane-close-capture-worker (worker lane-801)
        - Reviewer: post-feature-analyzer (worker lane-802)

        - Agent session capture waivers: none
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-07-capture.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-07-07T10:00:00+00:00',
    );

    $slug = 'feature';
    $archiveDir = write_finalization_gate_session_archive(repo: $repo, slug: $slug);
    // copy the full packet (with lanes + waivers: none) into archive loop.md so health parser sees labels
    $sourceLoop = "{$worktree}/.orbit/loop.md";
    if (is_file($sourceLoop)) {
        copy($sourceLoop, "{$archiveDir}/loop.md");
    }
    // manifest with zero healthy
    file_put_contents("{$archiveDir}/agent-sessions/manifest.json", json_encode([
        'schema_version' => 1,
        'providers' => ['codex' => ['missing' => 1]],
        'sessions' => [['worker_id' => 'lane-801', 'status' => 'missing', 'reason' => 'exact_marker_not_found']],
    ], JSON_THROW_ON_ERROR)
        .PHP_EOL);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('BLOCKED')
            ->toContain('zero healthy agent session captures')
            ->toContain('waiver');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('finalization merge gate blocks named lanes with zero active captures before archive exists', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: feature
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - test packet for active capture health gate
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - test coverage for merge blocking when named lanes have no active or archived healthy captures

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: none
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-07-capture.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-07-07T10:00:00+00:00',
    );

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('zero healthy agent session captures');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('finalization gate passes with waiver label naming missing providers for lanes-having packet', function (): void {
    [$repo, $worktree] = create_finalization_gate_fixture(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - test packet for capture health gate with waiver
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - test coverage for waiver row allowing zero healthy when lanes named

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: codex, grok (terminal kind unsupported in this env)
        MARKDOWN);

    commit_finalization_gate_file(
        worktree: $worktree,
        path: 'harness-signals/2026-07-07-capture.md',
        contents: "# Example\n",
    );
    write_finalization_gate_artifact(
        worktree: $worktree,
        gate: 'docs-lint',
        exitCode: 0,
        endedAt: '2026-07-07T10:00:00+00:00',
    );

    $slug = 'feature';
    $archiveDir = write_finalization_gate_session_archive(repo: $repo, slug: $slug);
    // copy the full packet (with lanes + waivers row) into archive loop.md
    $sourceLoop = "{$worktree}/.orbit/loop.md";
    if (is_file($sourceLoop)) {
        copy($sourceLoop, "{$archiveDir}/loop.md");
    }
    file_put_contents("{$archiveDir}/agent-sessions/manifest.json", json_encode([
        'schema_version' => 1,
        'providers' => ['codex' => ['unsupported' => 1]],
        'sessions' => [[
            'worker_id' => 'lane-801',
            'status' => 'unsupported',
            'reason' => 'terminal_kind_requires_waiver',
        ]],
    ], JSON_THROW_ON_ERROR)
        .PHP_EOL);

    try {
        $process = run_finalization_gate(repo: $repo, command: 'git merge feature');

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_gate_fixture(repo: $repo, worktree: $worktree);
    }
});

it('finalization lint rejects waiver rows that do not name a provider', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - lint packet for capture health gate with invalid waiver
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - lint coverage for rejecting generic waiver prose

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: unsupported
        MARKDOWN);

    try {
        $process = run_finalization_check_wrapper(
            cwd: $packetDir,
            args: ['--lint', "{$packetDir}/.orbit/loop.md"],
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toContain('zero healthy agent session captures');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('finalization lint blocks lanes-having packet with zero healthy active captures and no waiver', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - lint packet for capture health gate
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - lint coverage for zero healthy capture block when lanes named and no waiver row

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: none
        MARKDOWN);

    try {
        $process = run_finalization_check_wrapper(
            cwd: $packetDir,
            args: ['--lint', "{$packetDir}/.orbit/loop.md"],
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toContain('zero healthy agent session captures');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('finalization lint accepts healthy archived captures when active staging is absent', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - lint packet for archived capture health gate
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - lint coverage for healthy archived capture allowing named lanes

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: none
        MARKDOWN);

    try {
        $archiveDir = write_finalization_gate_session_archive($packetDir, 'lane-close-agent-session-capture');
        file_put_contents("{$archiveDir}/agent-sessions/manifest.json", json_encode([
            'schema_version' => 1,
            'providers' => ['grok' => ['ok' => 1]],
            'sessions' => [['worker_id' => 'lane-801', 'status' => 'ok']],
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);

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

it('finalization lint passes lanes-having packet with healthy active staged capture', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - lint packet for capture health gate with healthy active staged capture
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - lint coverage for healthy active staged capture allowing named lanes

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: none
        MARKDOWN);

    try {
        $stagedDir = "{$packetDir}/.orbit/agent-sessions/grok/lane-close-capture-worker-801";
        mkdir($stagedDir, recursive: true);
        file_put_contents("{$stagedDir}/manifest.json", json_encode([
            'schema_version' => 1,
            'provider' => 'grok',
            'status' => 'ok',
            'worker_id' => 'lane-801',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);

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

it('finalization lint ignores direct provider transaction backup manifests', function (): void {
    $packetDir = make_finalization_lint_dir(capture_health_finalization_packet());

    try {
        $backupDir = "{$packetDir}/.orbit/agent-sessions/grok/.lane-close-capture-worker-801.backup-review";
        mkdir($backupDir, recursive: true);
        file_put_contents("{$backupDir}/manifest.json", json_encode([
            'schema_version' => 1,
            'provider' => 'grok',
            'status' => 'ok',
            'worker_id' => 'lane-801',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);

        $process = run_finalization_check_wrapper(
            cwd: $packetDir,
            args: ['--lint', "{$packetDir}/.orbit/loop.md"],
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getOutput())
            ->toContain('zero healthy agent session captures');
    } finally {
        remove_finalization_lint_dir($packetDir);
    }
});

it('finalization lint counts direct provider backup-shaped evidence with an empty suffix', function (): void {
    $packetDir = make_finalization_lint_dir(capture_health_finalization_packet());

    try {
        $evidenceDir = "{$packetDir}/.orbit/agent-sessions/grok/.lane.backup-";
        mkdir($evidenceDir, recursive: true);
        file_put_contents("{$evidenceDir}/manifest.json", json_encode([
            'schema_version' => 1,
            'provider' => 'grok',
            'status' => 'ok',
            'worker_id' => 'lane-801',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);

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

it('finalization lint counts non-dot backup-shaped evidence', function (): void {
    $packetDir = make_finalization_lint_dir(capture_health_finalization_packet());

    try {
        $evidenceDir = "{$packetDir}/.orbit/agent-sessions/grok/lane-close-capture-worker-801.backup-review";
        mkdir($evidenceDir, recursive: true);
        file_put_contents("{$evidenceDir}/manifest.json", json_encode([
            'schema_version' => 1,
            'provider' => 'grok',
            'status' => 'ok',
            'worker_id' => 'lane-801',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);

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

it('finalization gate unchanged for laneless loops even with zero captures', function (): void {
    $packetDir = make_finalization_lint_dir(<<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - laneless test packet
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - laneless loop; no named worker/reviewer/analyzer lanes so no capture health requirement
        MARKDOWN);

    try {
        $slug = 'laneless-ok';
        $archiveDir = write_finalization_gate_session_archive($packetDir, $slug);
        // empty / no healthy is fine for laneless
        $process = run_finalization_check_wrapper(
            cwd: $packetDir,
            args: ['--lint', "{$packetDir}/.orbit/loop.md"],
        );

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->toContain('PASS');
    } finally {
        remove_finalization_lint_dir($packetDir);
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
    file_put_contents(
        filename: "{$repo}/.gitignore",
        data: "/.orbit/*\n!/.orbit/sessions/\n!/.orbit/sessions/**\n",
    );

    mkdir("{$repo}/apps/cli", recursive: true);
    mkdir("{$repo}/bin", recursive: true);
    file_put_contents(filename: "{$repo}/apps/cli/runtime.php", data: "<?php\n");
    copy(repo_path('bin/orbit-loop-contract.php'), "{$repo}/bin/orbit-loop-contract.php");
    copy(repo_path('bin/orbit-quality-subgates.php'), "{$repo}/bin/orbit-quality-subgates.php");
    copy(repo_path('bin/quality-check.sh'), "{$repo}/bin/quality-check.sh");

    run_fixture_command(cwd: $repo, command: ['git', 'add', 'HARNESS.md', 'AGENTS.md', '.gitignore', 'apps', 'bin']);
    run_fixture_command(cwd: $repo, command: ['git', 'commit', '-m', 'Initial commit']);
    run_fixture_command(cwd: $repo, command: ['git', 'branch', 'feature']);
    run_fixture_command(cwd: $repo, command: ['git', 'worktree', 'add', $worktree, 'feature']);

    mkdir("{$worktree}/.orbit", recursive: true);
    if (str_contains($loopMarkdown, '# Orbit Feature Loop') && ! str_contains($loopMarkdown, '## Slices')) {
        $loopMarkdown .= "\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-example.md` | ready | none |\n";
    }
    $loopMarkdown = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $loopMarkdown);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $loopMarkdown =
        preg_replace_callback(
            '/(\.orbit\/slices\/01-example\.md` \| complete \| )[0-9a-f]{40}/',
            static fn (array $match): string => $match[1].$head,
            $loopMarkdown,
        ) ?? $loopMarkdown;
    file_put_contents(filename: "{$worktree}/.orbit/loop.md", data: $loopMarkdown);
    finalization_seed_slice_packets($worktree, $loopMarkdown);

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

/** @mago-expect lint:excessive-parameter-list */
function compact_feature_loop_packet(
    string $review = 'passed - reviewer example - human-judgment=not-required',
    string $acceptance = 'accepted - automated - reviewer-confirmed no-human-judgment',
    string $featureTip = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $mainTip = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    string $state = 'accepted',
    string $venue = 'automated',
    ?string $reviewedTip = null,
    string $runtime = 'not applicable - no runtime proof venue',
    string $blastRadius = 'not-required - local change',
    string $owned = 'loop tooling',
    bool $includeSlices = true,
): string {
    $reviewedTip ??= $featureTip;
    $terminal = in_array($state, ['prove', 'accept', 'accepted', 'land'], true);
    $slices = $includeSlices
        ? "\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-example.md` | "
        .($terminal ? 'complete' : 'ready')
        .' | '
        .($terminal ? $featureTip : 'none')
        ." |\n"
        : '';

    return <<<MARKDOWN
        # Orbit Feature Loop

        - Scratchpad: solo://proj/4/scratchpad/example--1
        - Worktree: .worktrees/example
        - Branch: feature

        ## Goal

        Prove the compact feature loop.

        ## Scope

        - Owned: {$owned}
        - Constraints: no manual E2E
        - Out of scope: product behavior

        ## Proof

        - Verification:
          - focused: passed - focused test
          - broader: passed - quality artifact
          - runtime: {$runtime}
        - Blast radius: {$blastRadius}
        - Review: {$review}
        - Reviewed feature tip: {$reviewedTip}
        - Acceptance venue: {$venue}
        - Acceptance: {$acceptance}
        - Accepted feature tip: {$featureTip}
        - Accepted main tip: {$mainTip}

        {$slices}

        ## Status

        - State: {$state}
        - Blocker: none

        ## Feedback

        - Events: .orbit/feedback.jsonl
        MARKDOWN;
}

function finalization_bind_compact_worktree(string $packet, string $worktree): string
{
    return str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
}

function write_compact_feature_loop_for_fixture(
    string $repo,
    string $worktree,
    string $venue = 'automated',
    string $runtime = 'not applicable - no runtime proof venue',
    string $blastRadius = 'not-required - local change',
): void {
    $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());

    if ($venue !== 'automated' && str_starts_with($runtime, 'passed')) {
        finalization_seed_runtime_evidence($worktree);
    }

    $packet = compact_feature_loop_packet(
        featureTip: $featureTip,
        mainTip: $mainTip,
        venue: $venue,
        runtime: $runtime,
        blastRadius: $blastRadius,
    );
    if (! str_contains($packet, '## Slices')) {
        $packet .= "\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-example.md` | ready | none |\n";
    }
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents("{$worktree}/.orbit/loop.md", $packet);
    finalization_seed_slice_packets($worktree, $packet);
}

function write_multi_venue_feature_loop_for_fixture(string $repo, string $worktree, ?string $variant = null): void
{
    $candidate = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $baseTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());
    $mergeBase = trim(new Process(['git', 'merge-base', 'main', 'HEAD'], $worktree)->mustRun()->getOutput());
    $receipts = [];
    foreach (['browser', 'host-macos'] as $venue) {
        $receipt = "passed - candidate={$candidate}; base_tip={$baseTip}; merge_base={$mergeBase}; venue={$venue}; environment=dev-fixture; command=fixture {$venue}; expected=exit 0; observed=exit 0; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`";
        $receipts[$venue] = $receipt;
    }
    if ($variant === 'missing receipt') {
        unset($receipts['host-macos']);
    } elseif ($variant === 'stale candidate') {
        $receipts['browser'] = str_replace($candidate, str_repeat('c', 40), $receipts['browser']);
    } elseif ($variant === 'stale base tip') {
        $receipts['browser'] = str_replace($baseTip, str_repeat('d', 40), $receipts['browser']);
    } elseif ($variant === 'stale merge base') {
        $receipts['browser'] = str_replace($mergeBase, str_repeat('e', 40), $receipts['browser']);
    } elseif ($variant === 'duplicate receipt') {
        $receipts['browser'] .= "\n          - runtime[browser]: {$receipts['browser']}";
    } elseif ($variant === 'unknown receipt') {
        $receipts['tablet'] = $receipts['browser'];
    }

    $runtime = "passed - aggregate multi-venue runtime proof\n";
    foreach ($receipts as $venue => $receipt) {
        $runtime .= "          - runtime[{$venue}]: {$receipt}\n";
    }
    $packet = compact_feature_loop_packet(
        featureTip: $candidate,
        mainTip: $baseTip,
        venue: 'browser',
        runtime: $runtime,
    );
    $packet = str_replace('- Acceptance venue: browser', '- Acceptance venues: browser, host-macos', $packet);
    $packet = str_replace('- Verification:\n          - focused:', '- Verification:\n          - focused:', $packet);
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    file_put_contents("{$worktree}/.orbit/loop.md", $packet);
    finalization_seed_runtime_evidence($worktree);
    finalization_seed_slice_packets($worktree, $packet);
}

function finalization_seed_slice_packets(string $worktree, string $loopMarkdown): void
{
    preg_match_all('/^\| `\.orbit\/slices\/([^`]+)\.md` \|/m', $loopMarkdown, $matches);
    foreach (array_unique($matches[1]) as $id) {
        if (! is_dir("{$worktree}/.orbit/slices")) {
            mkdir("{$worktree}/.orbit/slices", recursive: true);
        }
        file_put_contents(
            filename: "{$worktree}/.orbit/slices/{$id}.md",
            data: finalization_slice_packet($id),
        );
    }
}

function finalization_structured_runtime(
    string $worktree,
    string $candidate,
    string $venue = 'retained-incus',
    string $observed = 'exit 0',
): string {
    finalization_seed_runtime_evidence($worktree);

    return (
        'passed - candidate='
        .$candidate
        .'; venue='
        .$venue
        .'; environment=dev-fixture'
        .'; target=orbit fixture'
        .'; expected=exit 0'
        .'; observed='
        .$observed
        .'; result=passed'
        .'; evidence=`.orbit/evidence/runtime-proof.txt`'
    );
}

function finalization_seed_runtime_evidence(
    string $worktree,
    string $relative = '.orbit/evidence/runtime-proof.txt',
): void {
    $path = $worktree.'/'.ltrim($relative, '/');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    file_put_contents($path, "runtime proof fixture\n");
}

function write_finalization_acceptance_event(
    string $worktree,
    string $candidateCommit,
    string $sourceRef,
    string $surface,
): void {
    $event = [
        'schema_version' => 1,
        'type' => 'feedback.recorded',
        'id' => 'feedback-acceptance-example',
        'recorded_at' => '2026-07-10T20:00:00+00:00',
        'raw_text' => 'I accept this candidate.',
        'session_ref' => $sourceRef,
        'candidate_commit' => $candidateCommit,
        'surface' => $surface,
        'actionable' => false,
        'context' => ['kind' => 'acceptance'],
        'evidence' => [],
    ];

    file_put_contents(
        "{$worktree}/.orbit/feedback.jsonl",
        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

function full_template_finalization_packet(): string
{
    return <<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context

        - Scratchpad: solo://apps/12/scratchpads/34
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

function make_finalization_lint_dir(string $loopMarkdown, bool $includeSlices = true): string
{
    $packetDir = sys_get_temp_dir().'/orbit-finalization-lint-'.bin2hex(random_bytes(6));
    mkdir($packetDir, recursive: true);
    run_fixture_command($packetDir, ['git', 'init', '--initial-branch=main']);
    run_fixture_command($packetDir, ['git', 'config', 'user.email', 'orbit@example.test']);
    run_fixture_command($packetDir, ['git', 'config', 'user.name', 'Orbit Test']);
    file_put_contents($packetDir.'/fixture.txt', "fixture\n");
    run_fixture_command($packetDir, ['git', 'add', 'fixture.txt']);
    run_fixture_command($packetDir, ['git', 'commit', '-m', 'Fixture']);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $packetDir)->mustRun()->getOutput());
    $loopMarkdown = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$packetDir, $loopMarkdown);
    $terminal = preg_match('/^- State: (prove|accept|accepted|land)$/m', $loopMarkdown) === 1;
    if (
        $terminal
        && preg_match(
            '/\| `\.orbit\/slices\/01-example\.md` \| (ready|complete) \| (none|[0-9a-f]{40}) \|/',
            $loopMarkdown,
            $match,
        ) === 1
        && ($match[2] === 'none'
        || preg_match('/^[0-9a-f]{40}$/', $match[2]) === 1)
    ) {
        $loopMarkdown =
            preg_replace_callback(
                '/(\.orbit\/slices\/01-example\.md` \| )(?:ready|complete) \| (?:none|[0-9a-f]{40})/',
                static fn (array $m): string => $m[1].'complete | '.$head,
                $loopMarkdown,
            ) ?? $loopMarkdown;
    }

    if (
        $includeSlices
        && str_contains($loopMarkdown, '# Orbit Feature Loop')
        && ! str_contains($loopMarkdown, '## Slices')
    ) {
        $loopMarkdown .= "\n## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-example.md` | ready | none |\n";
    }

    mkdir("{$packetDir}/.orbit", recursive: true);
    file_put_contents(filename: "{$packetDir}/.orbit/loop.md", data: $loopMarkdown);

    if ($includeSlices) {
        finalization_seed_slice_packets($packetDir, $loopMarkdown);
    }

    return $packetDir;
}

function finalization_slice_packet(string $id, string $depends = 'none'): string
{
    return (
        "# Orbit Feature Slice\n\n"
        ."- Slice: {$id}\n"
        ."- Depends on: {$depends}\n\n"
        ."## Outcome\n\n"
        ."## Scope\n- Included: finalization gate\n- Excluded: archive work\n\n"
        ."## Authority\n- Decisions: lifecycle contract\n- Product docs: feature lifecycle\n\n"
        ."## Proof\n- Focused: finalization lint tests\n"
    );
}

/** @return array{0:string,1:string,2:string,3:string} */
function finalization_git_chain_fixture(string $mode): array
{
    [$repo, $worktree] = create_finalization_gate_fixture(compact_feature_loop_packet(state: 'land'));
    file_put_contents($worktree.'/chain.txt', 'one');
    run_fixture_command($worktree, ['git', 'add', 'chain.txt']);
    run_fixture_command($worktree, ['git', 'commit', '-m', 'chain one']);
    $c1 = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    file_put_contents($worktree.'/chain.txt', 'two');
    run_fixture_command($worktree, ['git', 'add', 'chain.txt']);
    run_fixture_command($worktree, ['git', 'commit', '-m', 'chain two']);
    $c2 = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    file_put_contents($worktree.'/chain.txt', 'three');
    run_fixture_command($worktree, ['git', 'add', 'chain.txt']);
    run_fixture_command($worktree, ['git', 'commit', '-m', 'chain three']);
    $c3 = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $first = $mode === 'backwards' ? $c2 : $c1;
    if ($mode === 'non-ancestor') {
        $tree = trim(new Process(['git', 'rev-parse', 'HEAD^{tree}'], $worktree)->mustRun()->getOutput());
        $first = trim(new Process(['git', 'commit-tree', $tree, '-m', 'side'], $worktree)->mustRun()->getOutput());
    }
    $second = $c2 === $first && $mode === 'backwards' ? $c1 : $c2;
    $rows = "| `.orbit/slices/01-one.md` | complete | {$first} |\n| `.orbit/slices/02-two.md` | complete | {$second} |\n| `.orbit/slices/03-three.md` | complete | {$c3} |";
    $packet =
        preg_replace(
            '/\| `\.orbit\/slices\/01-example\.md` \| (?:ready|complete) \| (?:none|[0-9a-f]{40}) \|/',
            $rows,
            compact_feature_loop_packet(state: 'land'),
        ) ?? '';
    $packet = str_replace('- Worktree: .worktrees/example', '- Worktree: '.$worktree, $packet);
    if (is_file($worktree.'/.orbit/slices/01-example.md')) {
        unlink($worktree.'/.orbit/slices/01-example.md');
    }
    if (! is_dir($worktree.'/.orbit/slices')) {
        mkdir($worktree.'/.orbit/slices', recursive: true);
    }
    file_put_contents($worktree.'/.orbit/loop.md', $packet);
    file_put_contents($worktree.'/.orbit/slices/01-one.md', finalization_slice_packet('01-one'));
    file_put_contents($worktree.'/.orbit/slices/02-two.md', finalization_slice_packet('02-two', '01-one'));
    file_put_contents($worktree.'/.orbit/slices/03-three.md', finalization_slice_packet('03-three', '02-two'));

    return [$repo, $worktree, $packet, $c3];
}

function capture_health_finalization_packet(): string
{
    return <<<'MARKDOWN'
        # Orbit Current Slice State

        ## Feature Context
        - Scratchpad: solo://proj/4/scratchpad/loop-improvement-rev--237
        - Worktree: .worktrees/lane-close-agent-session-capture
        - Branch: lane-close-agent-session-capture
        - Current slice: lane close capture

        ## Final Distillation

        - Loop outcome:
          - complete
        - Required verification:
          - Retained topology proof: not applicable - repo tooling only
          - `composer quality-check`: not applicable - focused pest only for this test
        - Finalization gate fit:
          - lint packet for capture health gate
        - Distillation packet:
          - Location: `.orbit/loop.md`
          - Includes objective/final diff: yes
          - Includes worker/reviewer/terminal/evidence pointers: yes
          - Includes orchestrator steering notes: yes
        - Fresh analyzer:
          - deferred - Solo analyzer capacity unavailable this session
        - Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - capture health traversal boundary fixture

        - Worker: lane-close-capture-worker (worker lane-801)

        - Agent session capture waivers: none
        MARKDOWN;
}

function remove_finalization_lint_dir(string $packetDir): void
{
    if (! str_contains($packetDir, '/orbit-finalization-lint-')) {
        return;
    }

    new Process(['rm', '-rf', $packetDir])->run();
}

function write_finalization_gate_session_archive(
    string $repo,
    string $slug,
    bool $timestamped = true,
    bool $commit = true,
): string {
    $archiveName = $timestamped ? "2026-07-02-101500-{$slug}" : $slug;
    $archiveDir = "{$repo}/.orbit/sessions/{$archiveName}";

    mkdir("{$archiveDir}/agent-sessions", recursive: true);
    file_put_contents(filename: "{$archiveDir}/loop.md", data: "# Archived loop\n");
    file_put_contents(
        filename: "{$archiveDir}/agent-sessions/manifest.json",
        data: json_encode(['schema_version' => 1, 'sessions' => []], JSON_THROW_ON_ERROR).PHP_EOL,
    );

    if ($commit) {
        commit_finalization_session_archive($repo, $archiveDir);
    }

    return $archiveDir;
}

function write_compact_finalization_gate_session_archive(
    string $repo,
    string $worktree,
    string $slug,
    bool $includeFeedback = false,
    bool $commit = true,
): string {
    $archiveDir = "{$repo}/.orbit/sessions/2026-07-10-180000-{$slug}";
    mkdir($archiveDir, recursive: true);
    copy("{$worktree}/.orbit/loop.md", "{$archiveDir}/loop.md");

    $copiedEntries = ['loop.md'];

    if ($includeFeedback) {
        copy("{$worktree}/.orbit/feedback.jsonl", "{$archiveDir}/feedback.jsonl");
        $copiedEntries[] = 'feedback.jsonl';
    }

    sort($copiedEntries);
    $entryDigests = [];

    foreach ($copiedEntries as $entry) {
        $entryDigests[$entry] = hash_file('sha256', "{$archiveDir}/{$entry}");
    }

    $loop = (string) file_get_contents("{$archiveDir}/loop.md");
    preg_match('/^- Accepted feature tip:\s*([0-9a-f]{40})$/m', $loop, $acceptedFeature);
    preg_match('/^- Accepted main tip:\s*([0-9a-f]{40})$/m', $loop, $acceptedMain);
    $branchTip = trim(new Process(['git', 'rev-parse', 'refs/heads/feature'], $repo)->mustRun()->getOutput());

    file_put_contents(
        "{$archiveDir}/orbit-session-archive.json",
        json_encode([
            'schema_version' => 2,
            'archive_mode' => 'compact',
            'branch' => 'feature',
            'candidate_commit' => $branchTip,
            'accepted_feature_tip' => $acceptedFeature[1] ?? '',
            'accepted_main_tip' => $acceptedMain[1] ?? '',
            'copied_entries' => $copiedEntries,
            'entry_digests' => $entryDigests,
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL,
    );

    if ($commit) {
        commit_finalization_session_archive($repo, $archiveDir);
    }

    return $archiveDir;
}

function commit_finalization_session_archive(string $repo, string $archiveDir): void
{
    if (! is_dir("{$repo}/.git") && ! is_file("{$repo}/.git")) {
        return;
    }

    $relativeArchive = ltrim(str_replace($repo, '', $archiveDir), '/');
    $sessionsDir = "{$repo}/.orbit/sessions";
    $indexPath = "{$sessionsDir}/index.json";
    $basename = basename($archiveDir);

    $records = [];

    if (is_file($indexPath)) {
        $existing = json_decode((string) file_get_contents($indexPath), true);
        if (is_array($existing) && isset($existing['records']) && is_array($existing['records'])) {
            $records = $existing['records'];
        }
    }

    $records = array_values(array_filter(
        $records,
        static fn (mixed $record): bool => ! is_array($record) || ($record['archive'] ?? null) !== $basename,
    ));
    $records[] = [
        'archive' => $basename,
        'slug' => preg_replace('/^\d{4}-\d{2}-\d{2}-\d{6}-/', '', $basename) ?: $basename,
        'timestamp' => preg_match('/^(\d{4}-\d{2}-\d{2}-\d{6})-/', $basename, $m) === 1 ? $m[1] : '2026-07-10-180000',
    ];

    file_put_contents(
        $indexPath,
        json_encode([
            'schema_version' => 2,
            'generated_from' => '.orbit/sessions/YYYY-MM-DD-HHMMSS-<slug>',
            'record_count' => count($records),
            'records' => $records,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            .PHP_EOL,
    );

    run_fixture_command($repo, ['git', 'add', '--', $relativeArchive, '.orbit/sessions/index.json']);
    run_fixture_command($repo, ['git', 'commit', '-m', "Archive session {$basename}"]);
}

function land_finalization_gate_feature(string $repo): void
{
    run_fixture_command($repo, ['git', 'merge', '--no-ff', '--no-edit', 'feature']);
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
        'producer' => $gate === 'quality-check' ? 'quality-check.sh' : 'quality-gate-run',
        'command' => "composer {$gate}",
        'mode' => 'check',
        'exit_code' => $exitCode,
        'duration_ms' => 1000,
        'started_at' => '2026-06-25T09:59:59+00:00',
        'ended_at' => $endedAt,
        'git' => [
            'branch' => 'feature',
            'commit' => trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput()),
            'dirty' => false,
        ],
        'subgates' => $gate === 'quality-check' ? finalization_quality_check_subgates() : [],
    ];

    $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $timestamp = str_replace([':', '+'], '-', $endedAt);

    file_put_contents(
        filename: "{$artifactDirectory}/{$gate}-{$timestamp}.json",
        data: $encodedPayload.PHP_EOL,
    );
}

function latest_finalization_artifact_path(string $worktree, string $gate): string
{
    $paths = glob("{$worktree}/.orbit/quality-gates/{$gate}-*.json") ?: [];
    rsort($paths);

    return $paths[0] ?? throw new RuntimeException("Missing {$gate} fixture artifact");
}

/**
 * @param  list<string>  $declaredLabels
 * @param  list<string>|null  $producerLabels
 */
function commit_finalization_candidate_quality_labels(
    string $worktree,
    array $declaredLabels,
    ?array $producerLabels = null,
): void {
    $producerLabels ??= $declaredLabels;
    $declarationBody = implode("\n", array_map(
        static fn (string $label): string => "    '{$label}',",
        $declaredLabels,
    ));
    $producerBody = implode("\n", array_map(
        static fn (string $label): string => "    {$label}",
        $producerLabels,
    ));

    $declaration = <<<PHP
        <?php

        declare(strict_types=1);

        const QUALITY_CHECK_EXPECTED_SUBGATES = [
        {$declarationBody}
        ];
        PHP;
    $producer = <<<BASH
        #!/usr/bin/env bash

        CHECK_LABELS=(
        {$producerBody}
        )
        BASH;

    $declarationPath = "{$worktree}/bin/orbit-quality-subgates.php";
    $producerPath = "{$worktree}/bin/quality-check.sh";
    file_put_contents($declarationPath, $declaration.PHP_EOL);
    file_put_contents($producerPath, $producer.PHP_EOL);
    run_fixture_command($worktree, ['git', 'add', 'bin/orbit-quality-subgates.php', 'bin/quality-check.sh']);
    run_fixture_command($worktree, ['git', 'commit', '-m', 'Change candidate quality labels']);
}

/** @param array<string, int> $subgates */
function rewrite_finalization_quality_subgates(string $worktree, array $subgates): void
{
    $artifactPath = latest_finalization_artifact_path($worktree, 'quality-check');
    $artifact = json_decode((string) file_get_contents($artifactPath), true, flags: JSON_THROW_ON_ERROR);
    $artifact['subgates'] = $subgates;

    file_put_contents(
        $artifactPath,
        json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

/**
 * Parse a bash array of quality-check labels from bin/quality-check.sh.
 * Mirrors quality_check_script_labels() in VerificationScriptsTest.
 *
 * @return list<string>
 */
function finalization_quality_check_script_labels(string $script, string $arrayName): array
{
    $pattern = '/^'.preg_quote($arrayName, '/').'=\\(\\R(?P<body>.*?)^\\)/ms';
    $matches = [];

    if (preg_match($pattern, $script, $matches) !== 1) {
        throw new RuntimeException("Expected quality-check script to define [{$arrayName}].");
    }

    $labelMatches = [];
    preg_match_all('/^    (?P<label>[a-z0-9_]+)$/m', $matches['body'], $labelMatches);

    return $labelMatches['label'];
}

/** @return array<string, int> */
function finalization_quality_check_subgates(): array
{
    $labels = [
        'agent_cargo_check',
        'agent_cargo_clippy',
        'agent_cargo_fmt',
        'agent_cargo_test',
        'cli_mago_analyze',
        'cli_mago_format',
        'cli_mago_lint',
        'cli_pest',
        'cli_rector',
        'core_mago_analyze',
        'core_mago_format',
        'core_mago_lint',
        'core_pest',
        'core_rector',
        'docs_lint',
        'docs_mago_analyze',
        'docs_mago_format',
        'docs_mago_lint',
        'docs_pest',
        'docs_rector',
        'docs_references',
        'docs_testing',
        'e2e_mago_analyze',
        'e2e_mago_format',
        'e2e_mago_lint',
        'e2e_rector',
        'gateway_mago_analyze',
        'gateway_mago_format',
        'gateway_mago_lint',
        'gateway_pest',
        'gateway_rector',
        'macos_cargo_check',
        'macos_cargo_clippy',
        'macos_cargo_fmt',
        'macos_cargo_test',
        'reverb_mago_analyze',
        'reverb_mago_format',
        'reverb_mago_lint',
        'sdk_mago_analyze',
        'sdk_mago_format',
        'sdk_mago_lint',
        'sdk_pest',
        'sdk_rector',
        'sdk_typescript_build',
        'sdk_typescript_runtime',
        'sdk_typescript_typecheck',
    ];

    return array_fill_keys($labels, 0);
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
