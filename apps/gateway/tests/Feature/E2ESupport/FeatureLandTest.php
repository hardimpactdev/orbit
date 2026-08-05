<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Slice 6 minimal RED contract: ownership, archive-commit ordering, one-step resume.
 * Fixture Git + fake Solo CLI only; no live destructive cleanup.
 */

it('blocks direct cleanup when the matching archive and index are present but uncommitted', function (): void {
    [$repo, $worktree] = land_create_fixture();

    try {
        land_commit_feature_change($worktree);
        land_write_accepted_loop($repo, $worktree);
        land_merge_feature($repo);
        $archive = land_write_compact_archive($repo, $worktree);
        land_write_session_index($repo, basename($archive));

        $process = land_run_finalization($repo, "git worktree remove {$worktree}");

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->toMatch('/tracked|committed|index\.json/i');
    } finally {
        land_remove_fixture($repo, $worktree);
    }
});

it('blocks solo project delete with --confirm-stop-running and path ownership mismatch', function (): void {
    [$repo, $worktree] = land_create_fixture();
    $solo = land_write_fake_solo_cli($repo);

    try {
        land_fake_solo_state($solo, [
            'projects' => [
                73 => ['id' => 73, 'path' => $worktree, 'name' => 'feature'],
                99 => ['id' => 99, 'path' => '/tmp/unrelated-other-project', 'name' => 'other'],
            ],
            'processes' => [
                501 => ['id' => 501, 'projectId' => 73, 'status' => 'running'],
            ],
        ]);

        $shortcut = land_run_finalization(
            $repo,
            escapeshellarg($solo['cli'])." projects delete 73 --confirm-stop-running --json",
        );
        $mismatch = land_run_finalization(
            $repo,
            escapeshellarg($solo['cli']).' projects delete 99 --json',
        );

        expect($shortcut->getExitCode())
            ->toBe(2, $shortcut->getErrorOutput().$shortcut->getOutput())
            ->and($shortcut->getErrorOutput())
            ->toContain('--confirm-stop-running')
            ->and($mismatch->getExitCode())
            ->toBe(2, $mismatch->getErrorOutput().$mismatch->getOutput())
            ->and(strtolower($mismatch->getErrorOutput()))
            ->toMatch('/path|ownership|worktree|canonical/');
    } finally {
        land_remove_fixture($repo, $worktree);
    }
});

it('exposes one-step resume after merge and keeps archive-commit as the next ordered phase', function (): void {
    [$repo, $worktree, $solo] = land_prepare(accepted: true, merged: true);

    try {
        $status = land_run_land($repo, land_args($worktree, $solo, ['--status']));

        expect($status->getExitCode())
            ->toBe(0, $status->getErrorOutput().$status->getOutput())
            ->and(strtolower($status->getOutput().$status->getErrorOutput()))
            ->toMatch('/phase[=:\s]+archive|next[_\s-]?action[=:\s]+archive/');

        $oneStep = land_run_land($repo, land_args($worktree, $solo, ['--one-step']));

        expect($oneStep->getExitCode())
            ->toBe(0, $oneStep->getErrorOutput().$oneStep->getOutput());

        $archives = glob("{$repo}/.orbit/sessions/*-feature") ?: [];
        expect($archives)->not->toBeEmpty('one-step after merge must construct the session archive');

        $afterArchive = land_run_land($repo, land_args($worktree, $solo, ['--status']));

        expect($afterArchive->getExitCode())
            ->toBe(0, $afterArchive->getErrorOutput().$afterArchive->getOutput())
            ->and(strtolower($afterArchive->getOutput().$afterArchive->getErrorOutput()))
            ->toMatch('/archive-commit|commit.*archive|index\.json/')
            ->and(trim(new Process(['git', 'status', '--porcelain', '--', '.orbit/sessions'], $repo)->mustRun()->getOutput()))
            ->not->toBe('');
    } finally {
        land_remove_fixture($repo, $worktree);
    }
});

it('completes a clean full land and is idempotent at done', function (): void {
    [$repo, $worktree, $solo] = land_prepare(accepted: true, merged: false, processes: [
        501 => ['id' => 501, 'projectId' => 73, 'status' => 'running'],
    ]);
    $otherWorktree = land_add_unrelated_worktree($repo);

    try {
        land_fake_solo_state($solo, [
            'projects' => [
                73 => ['id' => 73, 'path' => $worktree, 'name' => 'feature'],
                88 => ['id' => 88, 'path' => $otherWorktree, 'name' => 'unrelated'],
            ],
            'processes' => [
                501 => ['id' => 501, 'projectId' => 73, 'status' => 'running'],
                900 => ['id' => 900, 'projectId' => 88, 'status' => 'running'],
            ],
        ]);

        $full = land_run_land($repo, land_args($worktree, $solo));

        expect($full->getExitCode())
            ->toBe(0, $full->getErrorOutput().$full->getOutput())
            ->and($full->getOutput())
            ->toContain('phase=done')
            ->and(is_dir($worktree))->toBeFalse()
            ->and(land_branch_exists($repo, 'feature'))->toBeFalse()
            ->and(is_dir($otherWorktree))->toBeTrue()
            ->and(land_branch_exists($repo, 'unrelated'))->toBeTrue();

        $soloState = json_decode((string) file_get_contents($solo['state']), true, flags: JSON_THROW_ON_ERROR);
        expect($soloState['projects'])->not->toHaveKey(73)
            ->and($soloState['projects'])->toHaveKey(88)
            ->and($soloState['processes'][900]['status'] ?? null)->toBe('running');

        $again = land_run_land($repo, land_args($worktree, $solo));
        $headBefore = trim(new Process(['git', 'rev-parse', 'HEAD'], $repo)->mustRun()->getOutput());
        $again2 = land_run_land($repo, land_args($worktree, $solo));
        $headAfter = trim(new Process(['git', 'rev-parse', 'HEAD'], $repo)->mustRun()->getOutput());

        expect($again->getExitCode())->toBe(0, $again->getErrorOutput().$again->getOutput())
            ->and($again->getOutput())->toContain('phase=done')
            ->and($again2->getExitCode())->toBe(0)
            ->and($again2->getOutput())->toContain('phase=done')
            ->and($headAfter)->toBe($headBefore);
    } finally {
        land_remove_fixture($repo, $worktree);
        if (is_dir($otherWorktree)) {
            new Process(['rm', '-rf', $otherWorktree])->run();
        }
    }
});

it('refuses dirty feature worktrees and unmerged identity for cleanup mutations', function (): void {
    [$repo, $worktree, $solo] = land_prepare(accepted: true, merged: false);

    try {
        file_put_contents("{$worktree}/DIRTY.txt", "dirt\n");

        $dirty = land_run_land($repo, land_args($worktree, $solo, ['--one-step']));

        expect($dirty->getExitCode())
            ->toBe(1, $dirty->getErrorOutput().$dirty->getOutput())
            ->and(strtolower($dirty->getErrorOutput().$dirty->getOutput()))
            ->toMatch('/dirty|uncommitted|finalization blocked/');

        unlink("{$worktree}/DIRTY.txt");
        land_merge_feature($repo);
        $archive = land_write_compact_archive($repo, $worktree);
        land_write_session_index($repo, basename($archive));
        // Archive present but uncommitted: direct cleanup still blocked.
        $cleanup = land_run_finalization($repo, "git worktree remove {$worktree}");

        expect($cleanup->getExitCode())
            ->toBe(2)
            ->and($cleanup->getErrorOutput())
            ->toMatch('/tracked|committed|index\.json/i');
    } finally {
        land_remove_fixture($repo, $worktree);
    }
});

it('refuses self-cwd cleanup and distinguishes missing project from stale ownership', function (): void {
    [$repo, $worktree, $solo] = land_prepare(accepted: true, merged: true);

    try {
        $self = land_run_land($worktree, land_args($worktree, $solo, ['--status']));

        expect($self->getExitCode())
            ->toBe(1)
            ->and(strtolower($self->getErrorOutput()))
            ->toMatch('/self-cleanup|inside/');

        // Stale live project: path no longer matches the feature worktree.
        land_fake_solo_state($solo, [
            'projects' => [
                73 => ['id' => 73, 'path' => '/tmp/stale-other-path', 'name' => 'stale'],
            ],
            'processes' => [],
        ]);

        $stale = land_run_land($repo, land_args($worktree, $solo, ['--status']));

        expect($stale->getExitCode())
            ->toBe(1)
            ->and(strtolower($stale->getErrorOutput()))
            ->toMatch('/does not equal|path/');

        // Missing project is an idempotent completed Solo boundary after merge.
        land_fake_solo_state($solo, ['projects' => [], 'processes' => []]);
        $missing = land_run_land($repo, land_args($worktree, $solo, ['--status']));

        expect($missing->getExitCode())
            ->toBe(0, $missing->getErrorOutput().$missing->getOutput())
            ->and($missing->getOutput())
            ->toContain('phase=archive');

        land_fake_solo_state($solo, [
            'projects' => [
                73 => ['id' => 73, 'path' => $worktree, 'name' => 'feature'],
            ],
            'processes' => [],
        ]);
        $selfProject = land_run_land(
            $repo,
            land_args($worktree, $solo, ['--status']),
            ['SOLO_PROJECT_ID' => '73'],
        );

        expect($selfProject->getExitCode())
            ->toBe(1)
            ->and(strtolower($selfProject->getErrorOutput()))
            ->toMatch('/self-project|solo_project_id/');
    } finally {
        land_remove_fixture($repo, $worktree);
    }
});

it('one-step advances each LAND boundary from a seeded interrupted state', function (
    string $seed,
    string $expectedPhaseAfter,
    string $expectedNextNeedle,
): void {
    [$repo, $worktree, $solo] = land_prepare(
        accepted: true,
        merged: false,
        processes: [
            501 => ['id' => 501, 'projectId' => 73, 'status' => 'running'],
            502 => ['id' => 502, 'projectId' => 73, 'status' => 'running'],
        ],
    );

    try {
        land_seed_boundary($repo, $worktree, $solo, $seed);

        $before = land_run_land($repo, land_args($worktree, $solo, ['--status']));
        expect($before->getExitCode())->toBe(0, $before->getErrorOutput().$before->getOutput());

        $step = land_run_land($repo, land_args($worktree, $solo, ['--one-step']));
        expect($step->getExitCode())
            ->toBe(0, $step->getErrorOutput().$step->getOutput())
            ->and($step->getOutput())
            ->toContain("phase={$expectedPhaseAfter}")
            ->and(strtolower($step->getOutput()))
            ->toMatch($expectedNextNeedle);
    } finally {
        land_remove_fixture($repo, $worktree);
    }
})->with([
    // After one-step executes the seeded phase, output reports the *next* observed phase.
    'after merge' => ['after-merge', 'archive-commit', '/archive-commit|commit archive/'],
    'after archive construction' => ['after-archive', 'stop-process', '/stop 50[12]|stop-process/'],
    'after archive commit' => ['after-archive-commit', 'stop-process', '/stop 50[12]|stop-process/'],
    'after one process stop' => ['after-one-stop', 'delete-project', '/projects delete|delete-project/'],
    'after project deletion' => ['after-project-deleted', 'delete-branch', '/branch -d|delete-branch/'],
    'after worktree removal' => ['after-worktree-removed', 'done', '/next_action=none|phase=done/'],
    'after branch deletion' => ['after-branch-deleted', 'done', '/next_action=none|phase=done/'],
]);

it('fail-closes Solo lookup errors and only treats explicit not_found as idempotent', function (
    string $mode,
    int $expectedExit,
    string $needle,
): void {
    [$repo, $worktree] = land_create_fixture();
    $solo = land_write_fake_solo_cli($repo);

    try {
        land_commit_feature_change($worktree);
        land_write_accepted_loop($repo, $worktree);
        land_merge_feature($repo);
        $archive = land_write_compact_archive($repo, $worktree);
        land_write_session_index($repo, basename($archive));
        land_commit_sessions($repo, $archive);

        $cli = match ($mode) {
            'unavailable' => "{$repo}.solo-fake/missing-solo-cli",
            'malformed' => land_write_broken_solo_cli($repo, 'malformed'),
            'error' => land_write_broken_solo_cli($repo, 'error'),
            'not_found' => $solo['cli'],
            default => throw new RuntimeException($mode),
        };

        if ($mode === 'not_found') {
            land_fake_solo_state($solo, ['projects' => [], 'processes' => []]);
        }

        $command = escapeshellarg($cli).' projects delete 73 --json';
        $process = land_run_finalization($repo, $command);

        expect($process->getExitCode())
            ->toBe($expectedExit, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch($needle);
    } finally {
        land_remove_fixture($repo, $worktree);
    }
})->with([
    'binary unavailable' => ['unavailable', 2, '/unavailable|failed|blocked/'],
    'malformed json' => ['malformed', 2, '/malformed|failed|blocked/'],
    'non-not-found error' => ['error', 2, '/failed|blocked|permission/'],
    'explicit not_found' => ['not_found', 0, '/finalization: pass/'],
]);

it('strictly classifies Solo destructive commands', function (string $commandSuffix, string $needle): void {
    [$repo, $worktree, $solo] = land_prepare(accepted: true, merged: true);

    try {
        $archive = land_write_compact_archive($repo, $worktree);
        land_write_session_index($repo, basename($archive));
        land_commit_sessions($repo, $archive);

        $command = escapeshellarg($solo['cli']).' '.$commandSuffix;
        $process = land_run_finalization($repo, $command);

        expect($process->getExitCode())
            ->toBe(2, $process->getErrorOutput().$process->getOutput())
            ->and(strtolower($process->getErrorOutput().$process->getOutput()))
            ->toMatch($needle);
    } finally {
        land_remove_fixture($repo, $worktree);
    }
})->with([
    'extra operands' => ['projects delete 73 extra --json', '/extra operands|exactly one numeric|invalid|blocked/'],
    'unknown flag' => ['projects delete 73 --force --json', '/unknown option|blocked/'],
    'chained commands' => ['projects delete 73 --json && projects delete 74 --json', '/unchained|exactly one|blocked/'],
    'confirm-stop-running' => ['projects delete 73 --confirm-stop-running --json', '/confirm-stop-running/'],
]);

/**
 * @param  array<int, array<string, mixed>>  $processes
 * @return array{0: string, 1: string, 2: array{cli: string, state: string}}
 */
function land_prepare(bool $accepted, bool $merged, array $processes = []): array
{
    [$repo, $worktree] = land_create_fixture();
    $solo = land_write_fake_solo_cli($repo);

    if ($accepted) {
        land_commit_feature_change($worktree);
        land_write_accepted_loop($repo, $worktree);
    }

    land_fake_solo_state($solo, [
        'projects' => [
            73 => ['id' => 73, 'path' => $worktree, 'name' => 'feature'],
        ],
        'processes' => $processes,
    ]);

    if ($merged) {
        land_merge_feature($repo);
    }

    return [$repo, $worktree, $solo];
}

/**
 * @param  array{cli: string, state: string}  $solo
 * @param  list<string>  $extra
 * @return list<string>
 */
function land_args(string $worktree, array $solo, array $extra = []): array
{
    return [
        '--branch=feature',
        "--worktree={$worktree}",
        '--solo-project-id=73',
        "--solo-cli={$solo['cli']}",
        ...$extra,
    ];
}

function land_add_unrelated_worktree(string $repo): string
{
    $path = "{$repo}.unrelated";
    land_run($repo, ['git', 'branch', 'unrelated']);
    land_run($repo, ['git', 'worktree', 'add', $path, 'unrelated']);

    return $path;
}

function land_branch_exists(string $repo, string $branch): bool
{
    return (new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"], $repo))->run() === 0;
}

/**
 * @return array{0: string, 1: string}
 */
function land_create_fixture(): array
{
    $repo = sys_get_temp_dir().'/orbit-feature-land-'.bin2hex(random_bytes(6));
    $worktree = "{$repo}.feature";

    mkdir($repo, recursive: true);
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
    mkdir("{$repo}/apps/cli", recursive: true);
    file_put_contents("{$repo}/apps/cli/runtime.php", "<?php\n");

    land_run($repo, ['git', 'add', 'HARNESS.md', 'AGENTS.md', '.gitignore', 'apps']);
    land_run($repo, ['git', 'commit', '-m', 'Initial commit']);
    land_run($repo, ['git', 'branch', 'feature']);
    land_run($repo, ['git', 'worktree', 'add', $worktree, 'feature']);
    mkdir("{$worktree}/.orbit", recursive: true);

    return [$repo, $worktree];
}

function land_commit_feature_change(string $worktree): void
{
    file_put_contents("{$worktree}/HARNESS.md", "# Compact harness\n");
    land_run($worktree, ['git', 'add', 'HARNESS.md']);
    land_run($worktree, ['git', 'commit', '-m', 'Feature change']);
}

function land_write_accepted_loop(string $repo, string $worktree): void
{
    $featureTip = trim(new Process(['git', 'rev-parse', 'HEAD'], $worktree)->mustRun()->getOutput());
    $mainTip = trim(new Process(['git', 'rev-parse', 'main'], $repo)->mustRun()->getOutput());

    $artifactDir = "{$worktree}/.orbit/quality-gates";
    mkdir($artifactDir, recursive: true);
    $endedAt = '2026-08-05T12:00:00+00:00';
    $payload = [
        'gate' => 'docs-lint',
        'producer' => 'quality-gate-run',
        'command' => 'composer docs-lint',
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
        'subgates' => [],
    ];
    file_put_contents(
        "{$artifactDir}/docs-lint-2026-08-05T120000Z.json",
        json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    file_put_contents("{$worktree}/.orbit/loop.md", <<<MARKDOWN
# Orbit Feature Loop

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
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

function land_merge_feature(string $repo): void
{
    land_run($repo, ['git', 'merge', '--no-ff', '--no-edit', 'feature']);
}

function land_write_compact_archive(string $repo, string $worktree): string
{
    $archiveDir = "{$repo}/.orbit/sessions/2026-08-05-120000-feature";
    mkdir($archiveDir, recursive: true);
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
        ], JSON_THROW_ON_ERROR).PHP_EOL,
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
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL,
    );
}

/**
 * @return array{cli: string, state: string}
 */
function land_write_fake_solo_cli(string $repo): array
{
    // Keep the fake CLI outside the Git primary tree so archive-commit dirt checks stay focused.
    $dir = "{$repo}.solo-fake";
    mkdir($dir, recursive: true);
    $state = "{$dir}/state.json";
    $cli = "{$dir}/solo-cli";
    file_put_contents($state, json_encode(['projects' => [], 'processes' => []], JSON_THROW_ON_ERROR));
    $stateExport = var_export($state, true);
    file_put_contents($cli, <<<PHP
        #!/usr/bin/env php
        <?php
        declare(strict_types=1);
        \$statePath = {$stateExport};
        if (! is_file(\$statePath)) {
            fwrite(STDERR, "fake solo: missing state\\n");
            exit(1);
        }
        \$state = json_decode((string) file_get_contents(\$statePath), true, flags: JSON_THROW_ON_ERROR);
        \$args = [];
        foreach (array_slice(\$argv, 1) as \$arg) {
            if (\$arg === '--json') {
                continue;
            }
            \$args[] = \$arg;
        }
        \$emit = static function (array \$payload) use (\$statePath, &\$state): void {
            file_put_contents(\$statePath, json_encode(\$state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
            fwrite(STDOUT, json_encode(['ok' => true, 'data' => \$payload], JSON_THROW_ON_ERROR).PHP_EOL);
        };
        \$fail = static function (string \$code, string \$message): void {
            fwrite(STDOUT, json_encode(['ok' => false, 'error' => ['code' => \$code, 'message' => \$message]], JSON_THROW_ON_ERROR).PHP_EOL);
            exit(1);
        };
        if ((\$args[0] ?? null) === 'projects' && (\$args[1] ?? null) === 'get') {
            \$id = (int) (\$args[2] ?? 0);
            if (! isset(\$state['projects'][\$id]) && ! isset(\$state['projects'][(string) \$id])) {
                \$fail('not_found', "project {\$id} not found");
            }
            \$emit(\$state['projects'][\$id] ?? \$state['projects'][(string) \$id]);
            exit(0);
        }
        if ((\$args[0] ?? null) === 'projects' && (\$args[1] ?? null) === 'delete') {
            \$id = (int) (\$args[2] ?? 0);
            if (in_array('--confirm-stop-running', \$argv, true)) {
                \$fail('confirm_stop_running', 'confirm-stop-running accepted by fake');
            }
            if (! isset(\$state['projects'][\$id]) && ! isset(\$state['projects'][(string) \$id])) {
                \$fail('not_found', "project {\$id} not found");
            }
            unset(\$state['projects'][\$id], \$state['projects'][(string) \$id]);
            \$emit(['deleted' => \$id]);
            exit(0);
        }
        if ((\$args[0] ?? null) === 'processes' && (\$args[1] ?? null) === 'get') {
            \$id = (int) (\$args[2] ?? 0);
            if (! isset(\$state['processes'][\$id]) && ! isset(\$state['processes'][(string) \$id])) {
                \$fail('not_found', "process {\$id} not found");
            }
            \$emit(\$state['processes'][\$id] ?? \$state['processes'][(string) \$id]);
            exit(0);
        }
        if ((\$args[0] ?? null) === 'processes' && (\$args[1] ?? null) === 'list') {
            \$projectId = null;
            foreach (\$args as \$i => \$arg) {
                if (\$arg === '--project-id') {
                    \$projectId = (int) (\$args[\$i + 1] ?? 0);
                }
                if (str_starts_with(\$arg, '--project-id=')) {
                    \$projectId = (int) substr(\$arg, strlen('--project-id='));
                }
            }
            \$processes = array_values(array_filter(
                \$state['processes'],
                static fn (array \$p): bool => \$projectId === null || (int) \$p['projectId'] === \$projectId,
            ));
            \$emit(['processes' => \$processes]);
            exit(0);
        }
        if ((\$args[0] ?? null) === 'processes' && (\$args[1] ?? null) === 'stop') {
            \$id = (int) (\$args[2] ?? 0);
            if (! isset(\$state['processes'][\$id]) && ! isset(\$state['processes'][(string) \$id])) {
                \$fail('not_found', "process {\$id} not found");
            }
            \$key = isset(\$state['processes'][\$id]) ? \$id : (string) \$id;
            \$state['processes'][\$key]['status'] = 'stopped';
            \$emit(\$state['processes'][\$key]);
            exit(0);
        }
        \$fail('unsupported', 'unsupported fake solo command: '.implode(' ', \$args));
        PHP);
    chmod($cli, 0o755);

    return ['cli' => $cli, 'state' => $state];
}

/**
 * @param  array{cli: string, state: string}  $solo
 * @param  array{projects: array<int, array<string, mixed>>, processes: array<int, array<string, mixed>>}  $payload
 */
function land_fake_solo_state(array $solo, array $payload): void
{
    file_put_contents(
        $solo['state'],
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL,
    );
}

function land_run_finalization(string $repo, string $command, array $environment = []): Process
{
    $process = new Process(
        [PHP_BINARY, repo_path('bin/orbit-codex-pre-tool-use-hook'), $command],
        $repo,
        land_test_environment(['ORBIT_FINALIZATION_EXPLICIT' => '1', ...$environment]),
    );
    $process->run();

    return $process;
}

/**
 * @param  list<string>  $args
 * @param  array<string, string>  $environment
 */
function land_run_land(string $repo, array $args, array $environment = []): Process
{
    $land = repo_path('bin/orbit-feature-land');
    $process = new Process(
        [PHP_BINARY, $land, ...$args],
        $repo,
        land_test_environment($environment),
    );
    $process->run();

    return $process;
}

/**
 * @param  array<string, string|false>  $environment
 * @return array<string, string|false>
 */
function land_test_environment(array $environment = []): array
{
    // Symfony Process treats false as "unset". Clear inherited Solo identity
    // so fixture project id 73 is not treated as self-project cleanup.
    return array_merge([
        'SOLO_PROJECT_ID' => false,
        'SOLO_PROCESS_ID' => false,
    ], $environment);
}

/**
 * @param  array{cli: string, state: string}  $solo
 */
function land_seed_boundary(string $repo, string $worktree, array $solo, string $seed): void
{
    $advanceThroughArchiveCommit = static function () use ($repo, $worktree, $solo): void {
        land_merge_feature($repo);
        $archive = land_write_compact_archive($repo, $worktree);
        land_write_session_index($repo, basename($archive));
        land_commit_sessions($repo, $archive);
    };

    match ($seed) {
        'after-merge' => land_merge_feature($repo),
        'after-archive' => (static function () use ($repo, $worktree): void {
            land_merge_feature($repo);
            $archive = land_write_compact_archive($repo, $worktree);
            land_write_session_index($repo, basename($archive));
        })(),
        'after-archive-commit' => $advanceThroughArchiveCommit(),
        'after-one-stop' => (static function () use ($repo, $worktree, $solo, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            land_fake_solo_state($solo, [
                'projects' => [
                    73 => ['id' => 73, 'path' => $worktree, 'name' => 'feature'],
                ],
                'processes' => [
                    501 => ['id' => 501, 'projectId' => 73, 'status' => 'stopped'],
                    502 => ['id' => 502, 'projectId' => 73, 'status' => 'running'],
                ],
            ]);
        })(),
        'after-project-deleted' => (static function () use ($repo, $worktree, $solo, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            land_fake_solo_state($solo, ['projects' => [], 'processes' => []]);
        })(),
        'after-worktree-removed' => (static function () use ($repo, $worktree, $solo, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            land_fake_solo_state($solo, ['projects' => [], 'processes' => []]);
            land_run($repo, ['git', 'worktree', 'remove', $worktree]);
        })(),
        'after-branch-deleted' => (static function () use ($repo, $worktree, $solo, $advanceThroughArchiveCommit): void {
            $advanceThroughArchiveCommit();
            land_fake_solo_state($solo, ['projects' => [], 'processes' => []]);
            if (is_dir($worktree)) {
                land_run($repo, ['git', 'worktree', 'remove', $worktree]);
            }
            if (land_branch_exists($repo, 'feature')) {
                land_run($repo, ['git', 'branch', '-d', 'feature']);
            }
        })(),
        default => throw new RuntimeException("unknown seed {$seed}"),
    };
}

function land_commit_sessions(string $repo, string $archiveDir): void
{
    $relative = ltrim(str_replace($repo, '', $archiveDir), '/');
    land_run($repo, ['git', 'add', '--', $relative, '.orbit/sessions/index.json']);
    land_run($repo, ['git', 'commit', '-m', 'Archive session for feature']);
}

function land_write_broken_solo_cli(string $repo, string $mode): string
{
    $path = "{$repo}.solo-fake/broken-{$mode}-solo-cli";
    $body = match ($mode) {
        'malformed' => "#!/bin/sh\necho 'not-json'\nexit 0\n",
        'error' => "#!/bin/sh\necho '{\"ok\":false,\"error\":{\"code\":\"permission_denied\",\"message\":\"permission denied\"}}'\nexit 1\n",
        default => throw new RuntimeException($mode),
    };
    file_put_contents($path, $body);
    chmod($path, 0o755);

    return $path;
}

/**
 * @param  list<string>  $command
 */
function land_run(string $cwd, array $command): void
{
    (new Process($command, $cwd))->mustRun();
}

function land_remove_fixture(string $repo, string $worktree): void
{
    if (! str_contains($repo, '/orbit-feature-land-')) {
        return;
    }

    new Process(['rm', '-rf', $repo, $worktree, "{$repo}.solo-fake", "{$repo}.unrelated"])->run();
}
