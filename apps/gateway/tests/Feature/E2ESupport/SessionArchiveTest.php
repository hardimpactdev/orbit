<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('session archive stores a compact receipt by default', function (): void {
    $workspace = session_archive_workspace('compact-default');

    try {
        $paths = session_archive_paths($workspace);
        session_archive_prepare_accepted_feature($paths);
        file_put_contents(
            "{$paths['sourceOrbitDir']}/feedback.jsonl",
            session_archive_closed_feedback_json('feedback-compact-default'),
        );
        mkdir("{$paths['sourceOrbitDir']}/agent-sessions");
        file_put_contents("{$paths['sourceOrbitDir']}/agent-sessions/raw.txt", "large trace\n");

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180000',
            '--slug=compact-default',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ], full: false);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary($process);
        $archive = (string) $summary['archive_dir'];

        expect($summary)
            ->toHaveKey('schema_version', 2)
            ->toHaveKey('archive_mode', 'compact')
            ->toHaveKey('copied_entries', ['feedback.jsonl', 'loop.md'])
            ->toHaveKey('entry_digests')
            ->and($summary['entry_digests'])
            ->toBe([
                'feedback.jsonl' => hash_file('sha256', "{$archive}/feedback.jsonl"),
                'loop.md' => hash_file('sha256', "{$archive}/loop.md"),
            ])
            ->and("{$archive}/loop.md")
            ->toBeFile()
            ->and("{$archive}/feedback.jsonl")
            ->toBeFile()
            ->and("{$archive}/orbit-session-archive.json")
            ->toBeFile()
            ->and("{$archive}/agent-sessions")
            ->not->toBeDirectory()->and("{$archive}/evidence")
            ->not->toBeDirectory()->and($process->getErrorOutput())
            ->not->toContain('no Solo process context');
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('binds a compact receipt to the branch and accepted candidate identity', function (): void {
    $workspace = session_archive_workspace('compact-identity');

    try {
        $paths = session_archive_paths($workspace);
        $git = static function (array $arguments) use ($paths): string {
            $process = new Process(['git', ...$arguments], $paths['cwd']);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $git(['init', '--initial-branch=main']);
        $git(['config', 'user.email', 'orbit@example.test']);
        $git(['config', 'user.name', 'Orbit Test']);
        file_put_contents("{$paths['cwd']}/.gitignore", ".orbit/\n");
        file_put_contents("{$paths['cwd']}/README.md", "# Fixture\n");
        $git(['add', '.gitignore', 'README.md']);
        $git(['commit', '-m', 'Initial']);
        $mainTip = $git(['rev-parse', 'HEAD']);
        $git(['checkout', '-b', 'feature']);
        file_put_contents("{$paths['cwd']}/feature.txt", "candidate\n");
        $git(['add', 'feature.txt']);
        $git(['commit', '-m', 'Candidate']);
        $featureTip = $git(['rev-parse', 'HEAD']);
        file_put_contents($paths['loopPath'], <<<MARKDOWN
            # Orbit Feature Loop

            - Scratchpad: solo://proj/4/scratchpad/example--1
            - Worktree: {$paths['cwd']}
            - Branch: feature

            ## Goal

            Prove receipt identity.

            ## Scope

            - Owned: feature.txt
            - Constraints: none
            - Out of scope: none

            ## Proof

            - Verification:
              - focused: passed - fixture
              - broader: passed - fixture
              - runtime: not applicable - fixture
            - Review: passed - reviewer - non-observable
            - Reviewed feature tip: {$featureTip}
            - Acceptance venue: automated
            - Acceptance: accepted - automated - reviewer-confirmed non-observable
            - Accepted feature tip: {$featureTip}
            - Accepted main tip: {$mainTip}

            ## Status

            - State: accepted
            - Blocker: none

            ## Feedback

            - Events: .orbit/feedback.jsonl
            MARKDOWN);
        $git(['checkout', 'main']);
        $git(['merge', '--no-ff', '--no-edit', 'feature']);
        $git(['checkout', 'feature']);

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180004',
            '--slug=compact-identity',
            "--cwd={$paths['cwd']}",
        ], full: false);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary($process);

        expect($summary)
            ->toHaveKey('branch', 'feature')
            ->toHaveKey('candidate_commit', $featureTip)
            ->toHaveKey('accepted_feature_tip', $featureTip)
            ->toHaveKey('accepted_main_tip', $mainTip)
            ->and(array_keys($summary['entry_digests']))
            ->toBe($summary['copied_entries']);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('refuses compact archive activation outside the exact accepted landed feature worktree', function (
    string $mutation,
    string $reason,
): void {
    $workspace = session_archive_workspace('compact-wrong-identity-'.$mutation);

    try {
        $paths = session_archive_paths($workspace);
        $identity = session_archive_prepare_accepted_feature($paths, land: $mutation !== 'unlanded');
        $loop = (string) file_get_contents($paths['loopPath']);

        if ($mutation === 'branch') {
            $loop = str_replace('- Branch: feature', '- Branch: different', $loop);
        } elseif ($mutation === 'accepted') {
            $loop = str_replace(
                '- Accepted feature tip: '.$identity['featureTip'],
                '- Accepted feature tip: '.str_repeat('a', 40),
                $loop,
            );
        } elseif ($mutation === 'reviewed') {
            $loop = str_replace(
                '- Reviewed feature tip: '.$identity['featureTip'],
                '- Reviewed feature tip: '.str_repeat('b', 40),
                $loop,
            );
        }

        file_put_contents($paths['loopPath'], $loop);

        if ($mutation === 'main-cwd') {
            session_archive_git($paths['cwd'], ['checkout', 'main']);
        }

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180006',
            '--slug=compact-wrong-identity',
            "--cwd={$paths['cwd']}",
        ], full: false);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($reason)
            ->and(session_archive_directories($paths['archiveRoot']))
            ->toBe([]);
    } finally {
        remove_session_archive_workspace($workspace);
    }
})->with([
    'unlanded feature' => ['unlanded', 'not landed on main'],
    'wrong loop branch' => ['branch', 'loop branch does not equal current branch'],
    'wrong accepted tip' => ['accepted', 'accepted feature tip does not equal candidate HEAD'],
    'wrong reviewed tip' => ['reviewed', 'reviewed feature tip does not equal candidate HEAD'],
    'main checkout cwd' => ['main-cwd', 'feature branch'],
]);

it('session archive scans the fully constructed full archive before activation', function (): void {
    $workspace = session_archive_workspace('full-secret-boundary');

    try {
        $paths = session_archive_paths($workspace);
        $token = 'gho_'.str_repeat('g', 24);
        $loopBefore = (string) file_get_contents($paths['loopPath']);
        file_put_contents("{$paths['sourceOrbitDir']}/evidence/proof.txt", "captured {$token}\n");

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180002',
            '--slug=full-secret-boundary',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        $entries = is_dir($paths['archiveRoot'])
            ? array_values(array_diff(scandir($paths['archiveRoot']) ?: [], ['.', '..']))
            : [];

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('evidence/proof.txt')
            ->toContain('github-token')
            ->and($entries)
            ->toBe([])
            ->and((string) file_get_contents($paths['loopPath']))
            ->toBe($loopBefore);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive refuses a refresh that would remove immutable feedback', function (): void {
    $workspace = session_archive_workspace('monotonic-refresh');

    try {
        $paths = session_archive_paths($workspace);
        session_archive_prepare_accepted_feature($paths);
        $feedback = session_archive_closed_feedback_json('feedback-monotonic');
        file_put_contents("{$paths['sourceOrbitDir']}/feedback.jsonl", $feedback);
        $arguments = [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180003',
            '--slug=monotonic-refresh',
            "--cwd={$paths['cwd']}",
        ];
        $first = run_session_archive($arguments, full: false);
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput());
        $archive = (string) session_archive_summary($first)['archive_dir'];
        unlink("{$paths['sourceOrbitDir']}/feedback.jsonl");

        $refresh = run_session_archive(
            array_values(array_filter(
                $arguments,
                static fn (string $argument): bool => ! str_starts_with($argument, '--timestamp='),
            )),
            full: false,
        );

        expect($refresh->getExitCode())
            ->toBe(2)
            ->and($refresh->getErrorOutput())
            ->toContain('would remove archived entry')
            ->and((string) file_get_contents("{$archive}/feedback.jsonl"))
            ->toBe($feedback);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive never downgrades a refreshed full archive to compact', function (): void {
    $workspace = session_archive_workspace('preserve-full-refresh');

    try {
        $paths = session_archive_paths($workspace);
        $arguments = [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180010',
            '--slug=preserve-full-refresh',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ];

        $full = run_session_archive($arguments);
        expect($full->getExitCode())->toBe(0, $full->getErrorOutput());

        $refreshed = run_session_archive(
            array_values(array_filter(
                $arguments,
                static fn (string $argument): bool => ! str_starts_with($argument, '--timestamp='),
            )),
            full: false,
        );
        $summary = session_archive_summary($refreshed);
        $archive = (string) $summary['archive_dir'];

        expect($refreshed->getExitCode())
            ->toBe(0, $refreshed->getErrorOutput())
            ->and($summary)
            ->toHaveKey('mode', 'refreshed')
            ->toHaveKey('archive_mode', 'full')
            ->and("{$archive}/agent-sessions/manifest.json")
            ->toBeFile();
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive blocks before construction when compact state still contains a secret', function (): void {
    $workspace = session_archive_workspace('secret-boundary');

    try {
        $paths = session_archive_paths($workspace);
        $token = 'ghp_'.str_repeat('a', 24);
        $event = json_decode(
            session_archive_feedback_json('feedback-secret-boundary'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $event['raw_text'] = $token;
        file_put_contents(
            "{$paths['sourceOrbitDir']}/feedback.jsonl",
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180001',
            '--slug=secret-boundary',
            "--cwd={$paths['cwd']}",
        ], full: false);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('github-token')
            ->and(session_archive_directories($paths['archiveRoot']))
            ->toBe([]);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive rejects an invalid feedback stream before construction', function (): void {
    $workspace = session_archive_workspace('invalid-feedback');

    try {
        $paths = session_archive_paths($workspace);
        file_put_contents(
            "{$paths['sourceOrbitDir']}/feedback.jsonl",
            "{\"schema_version\":1,\"type\":\"unknown\",\"id\":\"bad\",\"recorded_at\":\"2026-07-10T18:00:00Z\"}\n",
        );

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180005',
            '--slug=invalid-feedback',
            "--cwd={$paths['cwd']}",
        ], full: false);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('Active feedback stream is invalid')
            ->toContain('unknown feedback event type')
            ->and(session_archive_directories($paths['archiveRoot']))
            ->toBe([]);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive rejects unresolved actionable feedback before construction', function (): void {
    $workspace = session_archive_workspace('unresolved-feedback');

    try {
        $paths = session_archive_paths($workspace);
        $event = json_decode(
            session_archive_feedback_json('feedback-unresolved'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $event['actionable'] = true;
        file_put_contents(
            "{$paths['sourceOrbitDir']}/feedback.jsonl",
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $process = run_session_archive([
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-180006',
            '--slug=unresolved-feedback',
            "--cwd={$paths['cwd']}",
        ], full: false);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('unresolved actionable feedback: feedback-unresolved')
            ->and(session_archive_directories($paths['archiveRoot']))
            ->toBe([]);
    } finally {
        remove_session_archive_workspace($workspace);
    }
});

it('session archive generates local time basenames from an explicit slug', function (): void {
    $workspace = session_archive_workspace(suffix: 'name-generation');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $beforeTimestamp = system_local_timestamp();
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--slug=loop-hardening',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);
        $afterTimestamp = system_local_timestamp();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);
        $basename = basename((string) $summary['archive_dir']);

        expect($summary)
            ->toHaveKey('mode', 'created')
            ->and($basename)
            ->toMatch('/^\d{4}-\d{2}-\d{2}-\d{6}-loop-hardening$/')
            ->and((string) $summary['archive_dir'])
            ->toBeDirectory();

        $archiveTimestamp = substr($basename, 0, 17);

        expect($archiveTimestamp >= $beforeTimestamp)
            ->toBeTrue("Archive timestamp {$archiveTimestamp} predates local run start {$beforeTimestamp}.")
            ->and($archiveTimestamp <= $afterTimestamp)
            ->toBeTrue("Archive timestamp {$archiveTimestamp} postdates local run end {$afterTimestamp}.");
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive falls back to the kebab-cased current branch name as slug', function (): void {
    $workspace = session_archive_workspace(suffix: 'branch-slug');

    try {
        $paths = session_archive_paths(workspace: $workspace);

        new Process(['git', 'init', '--quiet', '--initial-branch=Feature/Loop_Hardening', $paths['cwd']])
            ->mustRun();

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-01-100305',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);

        expect($summary)
            ->toHaveKey('mode', 'created')
            ->and(basename((string) $summary['archive_dir']))
            ->toBe('2026-07-01-100305-feature-loop-hardening');
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects explicit archive dir basenames outside the naming contract', function (): void {
    $workspace = session_archive_workspace(suffix: 'invalid-archive-dir');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $invalidArchiveDir = "{$paths['archiveRoot']}/20260701T112409Z-orbit-laravel-vite-env";
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-dir={$invalidArchiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not->toBe(0)->and($process->getErrorOutput())->toContain(
                '^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}-[a-z0-9-]+$',
            )->and($invalidArchiveDir)
            ->not->toBeDirectory();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive fails loudly when the active loop file is missing', function (): void {
    $workspace = session_archive_workspace(suffix: 'missing-loop');

    try {
        $paths = session_archive_paths(workspace: $workspace, withLoop: false);
        file_put_contents("{$paths['sourceOrbitDir']}/evidence/proof.txt", "proof\n");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--slug=missing-loop',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and($process->getErrorOutput())
            ->toContain("{$paths['sourceOrbitDir']}/loop.md");
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive refreshes the newest slug archive instead of minting duplicates', function (): void {
    $workspace = session_archive_workspace(suffix: 'idempotency');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $baseArguments = [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--slug=idempotent-slice',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ];

        $firstRun = run_session_archive(arguments: [...$baseArguments, '--timestamp=2026-07-01-100000']);

        expect($firstRun->getExitCode())->toBe(0, $firstRun->getErrorOutput());

        $firstSummary = session_archive_summary(process: $firstRun);
        $firstArchiveDir = (string) $firstSummary['archive_dir'];

        $secondRun = run_session_archive(arguments: $baseArguments);

        expect($secondRun->getExitCode())->toBe(0, $secondRun->getErrorOutput());

        $secondSummary = session_archive_summary(process: $secondRun);

        expect($secondSummary)
            ->toHaveKey('mode', 'refreshed')
            ->toHaveKey('archive_dir', $firstArchiveDir)
            ->and($secondRun->getErrorOutput())
            ->toContain('Refresh')
            ->and(session_archive_directories(archiveRoot: $paths['archiveRoot']))
            ->toBe(['2026-07-01-100000-idempotent-slice'])
            ->and(session_archive_transaction_residue($firstArchiveDir))
            ->toBe([])
            ->and("{$firstArchiveDir}/agent-sessions/manifest.json")
            ->toBeFile();

        file_put_contents(
            "{$paths['sourceOrbitDir']}/loop.md",
            "changed slice state\n",
            FILE_APPEND,
        );

        $thirdRun = run_session_archive(arguments: [...$baseArguments, '--timestamp=2026-07-01-120000']);

        expect($thirdRun->getExitCode())->toBe(0, $thirdRun->getErrorOutput());

        $thirdSummary = session_archive_summary(process: $thirdRun);

        expect($thirdSummary)
            ->toHaveKey('mode', 'created')
            ->and(session_archive_directories(archiveRoot: $paths['archiveRoot']))
            ->toBe(['2026-07-01-100000-idempotent-slice', '2026-07-01-120000-idempotent-slice']);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive writes its path back into the active loop evidence links', function (): void {
    $workspace = session_archive_workspace(suffix: 'write-back');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        file_put_contents($paths['loopPath'], <<<'MARKDOWN'
            # Orbit Current Slice State

            ## Evidence Links

            - Red output: .orbit/evidence/red.txt
            - Session archive: .orbit/sessions/2026-01-01-000000-stale-name

            ## Final Distillation

            - Outcome: shipped

            MARKDOWN);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-01-100305',
            '--slug=write-back',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);
        $archiveDir = (string) $summary['archive_dir'];
        $activeLoop = (string) file_get_contents($paths['loopPath']);

        expect($activeLoop)
            ->toContain('- Session archive: .orbit/sessions/2026-07-01-100305-write-back')
            ->not
            ->toContain('2026-01-01-000000-stale-name')
            ->and(substr_count($activeLoop, '- Session archive:'))
            ->toBe(1)
            ->and(strpos($activeLoop, '- Session archive:'))
            ->toBeGreaterThan((int) strpos($activeLoop, '## Evidence Links'))
            ->toBeLessThan((int) strpos($activeLoop, '## Final Distillation'))
            ->and((string) file_get_contents("{$archiveDir}/loop.md"))
            ->toBe($activeLoop);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive appends an evidence links section when the active loop lacks one', function (): void {
    $workspace = session_archive_workspace(suffix: 'write-back-append');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        file_put_contents($paths['loopPath'], "# Orbit Current Slice State\n");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-01-100306',
            '--slug=write-back-append',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $activeLoop = (string) file_get_contents($paths['loopPath']);
        $summary = session_archive_summary(process: $process);

        expect($activeLoop)
            ->toContain('## Evidence Links')
            ->toContain('- Session archive: .orbit/sessions/2026-07-01-100306-write-back-append')
            ->and((string) file_get_contents((string) $summary['archive_dir'].'/loop.md'))
            ->toBe($activeLoop);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive warns loudly when no solo process context exists', function (): void {
    $workspace = session_archive_workspace(suffix: 'context-warning');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-01-100307',
            '--slug=context-warning',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);
        $manifest = json_decode(
            (string) file_get_contents((string) $summary['archive_dir'].'/agent-sessions/manifest.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain("{$paths['sourceOrbitDir']}/loop.md")
            ->and($manifest['archive_dir'])
            ->toBe($summary['archive_dir'].'/agent-sessions')
            ->and($manifest['sessions'])
            ->toBe([]);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive completes with a warning when a prose-mentioned solo process cannot be resolved', function (): void {
    $workspace = session_archive_workspace(suffix: 'unresolved-process');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        file_put_contents($paths['loopPath'], <<<'MARKDOWN'
            # Orbit Current Slice State

            Solo process or analyzer: process 987654 handled the review pass.

            MARKDOWN);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-02-100000',
            '--slug=unresolved-process',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
            "--solo-cli={$workspace}/missing-solo-cli",
            "--solo-db={$workspace}/missing-solo.db",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);
        $archiveDir = (string) $summary['archive_dir'];

        expect($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain('987654')
            ->and($summary['discovered_solo_process_ids'])
            ->toBe([987654])
            ->and("{$archiveDir}/orbit-session-archive.json")
            ->toBeFile()
            ->and("{$archiveDir}/evidence/proof.txt")
            ->toBeFile()
            ->and("{$archiveDir}/loop.md")
            ->toBeFile();

        $manifest = json_decode(
            (string) file_get_contents("{$archiveDir}/agent-sessions/manifest.json"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($manifest['sessions'])
            ->toHaveCount(1)
            ->and($manifest['sessions'][0])
            ->toMatchArray([
                'status' => 'solo_process_not_found',
                'solo_process_id' => 987654,
            ]);

        $agentResults = $summary['agent_results'];

        expect($agentResults)
            ->toHaveCount(1)
            ->and($agentResults[0])
            ->toMatchArray([
                'status' => 'solo_process_not_found',
                'solo_process_id' => 987654,
            ]);

        $activeLoop = (string) file_get_contents($paths['loopPath']);

        expect($activeLoop)
            ->toContain('- Session archive: .orbit/sessions/2026-07-02-100000-unresolved-process')
            ->and((string) file_get_contents("{$archiveDir}/loop.md"))
            ->toBe($activeLoop);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

function system_local_timestamp(): string
{
    $process = new Process(['date', '+%Y-%m-%d-%H%M%S']);

    $process->mustRun();

    return trim($process->getOutput());
}

function session_archive_workspace(string $suffix): string
{
    $workspace = sys_get_temp_dir().'/orbit-session-archive-'.$suffix.'-'.bin2hex(random_bytes(6));

    mkdir($workspace, recursive: true);

    return $workspace;
}

function session_archive_feedback_json(string $id): string
{
    return json_encode([
        'schema_version' => 1,
        'type' => 'feedback.recorded',
        'id' => $id,
        'recorded_at' => '2026-07-10T18:00:00Z',
        'raw_text' => 'Retain this feedback.',
        'session_ref' => 'codex://threads/example#feedback',
        'candidate_commit' => str_repeat('a', 40),
        'surface' => 'cli.progress',
        'context' => [],
        'evidence' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

function session_archive_closed_feedback_json(string $id): string
{
    $recorded = session_archive_feedback_json($id);
    $promotion = json_encode([
        'schema_version' => 1,
        'type' => 'feedback.promoted',
        'id' => 'promotion-'.$id,
        'recorded_at' => '2026-07-10T18:01:00Z',
        'feedback_id' => $id,
        'scope' => 'cli.progress',
        'expectation' => 'Progress remains visible and monotonic.',
        'protection' => [
            'kind' => 'test',
            'reference' => 'bin/quality-check-progress-frame-check',
            'rejected_example' => 'progress-stalled',
            'accepted_example' => 'progress-monotonic',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    return $recorded.$promotion;
}

/**
 * @return array{cwd: string, home: string, sourceOrbitDir: string, archiveRoot: string, loopPath: string}
 */
function session_archive_paths(string $workspace, bool $withLoop = true): array
{
    $cwd = "{$workspace}/worktree";
    $home = "{$workspace}/home";
    $sourceOrbitDir = "{$cwd}/.orbit";
    $archiveRoot = "{$workspace}/archive-root";
    $loopPath = "{$sourceOrbitDir}/loop.md";

    mkdir("{$sourceOrbitDir}/evidence", recursive: true);
    mkdir($home, recursive: true);

    if ($withLoop) {
        file_put_contents($loopPath, "# Orbit Current Slice State\n\nNo worker sessions.\n");
        file_put_contents("{$sourceOrbitDir}/evidence/proof.txt", "proof\n");
    }

    return [
        'cwd' => $cwd,
        'home' => $home,
        'sourceOrbitDir' => $sourceOrbitDir,
        'archiveRoot' => $archiveRoot,
        'loopPath' => $loopPath,
    ];
}

/**
 * @param array{cwd: string, loopPath: string} $paths
 * @return array{mainTip: string, featureTip: string}
 */
function session_archive_prepare_accepted_feature(array $paths, bool $land = true): array
{
    session_archive_git($paths['cwd'], ['init', '--initial-branch=main']);
    session_archive_git($paths['cwd'], ['config', 'user.email', 'orbit@example.test']);
    session_archive_git($paths['cwd'], ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$paths['cwd']}/.gitignore", ".orbit/\n");
    file_put_contents("{$paths['cwd']}/README.md", "# Fixture\n");
    session_archive_git($paths['cwd'], ['add', '.gitignore', 'README.md']);
    session_archive_git($paths['cwd'], ['commit', '-m', 'Initial']);
    $mainTip = session_archive_git($paths['cwd'], ['rev-parse', 'HEAD']);
    session_archive_git($paths['cwd'], ['checkout', '-b', 'feature']);
    file_put_contents("{$paths['cwd']}/feature.txt", "candidate\n");
    session_archive_git($paths['cwd'], ['add', 'feature.txt']);
    session_archive_git($paths['cwd'], ['commit', '-m', 'Candidate']);
    $featureTip = session_archive_git($paths['cwd'], ['rev-parse', 'HEAD']);
    file_put_contents($paths['loopPath'], <<<MARKDOWN
        # Orbit Feature Loop

        - Scratchpad: solo://proj/4/scratchpad/example--1
        - Worktree: {$paths['cwd']}
        - Branch: feature

        ## Goal

        Prove compact archive identity.

        ## Scope

        - Owned: feature.txt
        - Constraints: none
        - Out of scope: none

        ## Proof

        - Verification:
          - focused: passed - fixture
          - broader: passed - fixture
          - runtime: not applicable - fixture
        - Review: passed - reviewer - non-observable
        - Reviewed feature tip: {$featureTip}
        - Acceptance venue: automated
        - Acceptance: accepted - automated - reviewer-confirmed non-observable
        - Accepted feature tip: {$featureTip}
        - Accepted main tip: {$mainTip}

        ## Status

        - State: accepted
        - Blocker: none

        ## Feedback

        - Events: .orbit/feedback.jsonl
        MARKDOWN);

    if ($land) {
        session_archive_git($paths['cwd'], ['checkout', 'main']);
        session_archive_git($paths['cwd'], ['merge', '--no-ff', '--no-edit', 'feature']);
        session_archive_git($paths['cwd'], ['checkout', 'feature']);
    }

    return ['mainTip' => $mainTip, 'featureTip' => $featureTip];
}

/** @param list<string> $arguments */
function session_archive_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * @param list<string> $arguments
 */
function run_session_archive(array $arguments, bool $full = true): Process
{
    $modeArguments = $full ? ['--full'] : [];
    $process = new Process(
        [repo_path('bin/orbit-session-archive'), ...$modeArguments, ...$arguments],
        repo_path(),
        [
            'SOLO_PROCESS_ID' => false,
            'SOLO_PROJECT_ID' => false,
        ],
    );

    $process->run();

    return $process;
}

function run_session_archive_copy_swap_harness(string $workspace): Process
{
    $harness = <<<'PHP'
        declare(strict_types=1);

        require $argv[1];

        $workspace = $argv[2];
        $source = "{$workspace}/source.txt";
        $external = "{$workspace}/external.txt";
        $target = "{$workspace}/target.txt";
        file_put_contents($source, "safe source\n");
        file_put_contents($external, "external secret\n");
        $failure = null;

        try {
            orbitSessionArchiveCopyFile(
                $source,
                $target,
                static function () use ($source, $external): void {
                    unlink($source);
                    symlink($external, $source);
                },
            );
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        if ($failure === null || ! str_contains($failure, 'changed before copy')) {
            fwrite(STDERR, "Source swap was not rejected: ".($failure ?? 'no failure')."\n");
            exit(103);
        }

        if (file_exists($target) || file_get_contents($external) !== "external secret\n") {
            fwrite(STDERR, "Source swap wrote external bytes or left a target.\n");
            exit(104);
        }
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $harness,
        repo_path('bin/orbit-session-archive-filesystem.php'),
        $workspace,
    ], repo_path());
    $process->run();

    return $process;
}

function run_session_archive_swap_harness(string $workspace): Process
{
    $harness = <<<'PHP'
        declare(strict_types=1);

        $helperPath = $argv[1];
        $workspace = $argv[2];

        if (! is_file($helperPath)) {
            fwrite(STDERR, "Archive filesystem helper is missing: {$helperPath}\n");
            exit(86);
        }

        require $helperPath;

        if (! function_exists('swapArchiveDirectories')) {
            fwrite(STDERR, "Archive filesystem helper must define swapArchiveDirectories().\n");
            exit(87);
        }

        $archiveDir = "{$workspace}/archive-final";
        $temporaryArchiveDir = "{$workspace}/.archive-final.tmp-test";
        $backupDir = "{$workspace}/.archive-final.backup-test";
        mkdir($archiveDir);
        mkdir($temporaryArchiveDir);
        file_put_contents("{$archiveDir}/previous-final.txt", "old final\n");
        file_put_contents("{$temporaryArchiveDir}/new-final.txt", "new final\n");

        $renameCalls = [];
        $rename = static function (string $source, string $destination) use (&$renameCalls): bool {
            $renameCalls[] = [$source, $destination];

            if (count($renameCalls) === 2) {
                return false;
            }

            return rename($source, $destination);
        };
        $failureObserved = false;

        try {
            swapArchiveDirectories($temporaryArchiveDir, $archiveDir, $backupDir, $rename);
        } catch (RuntimeException) {
            $failureObserved = true;
        }

        if (! $failureObserved) {
            fwrite(STDERR, "Second rename failure was not reported.\n");
            exit(88);
        }

        if (count($renameCalls) !== 3) {
            fwrite(STDERR, 'Expected final-to-backup, failed temp-to-final, and backup-to-final renames.\n');
            exit(89);
        }

        if (! is_file("{$archiveDir}/previous-final.txt") || is_file("{$archiveDir}/new-final.txt")) {
            fwrite(STDERR, "Old final was not restored exactly.\n");
            exit(90);
        }

        if (file_exists($temporaryArchiveDir) || file_exists($backupDir)) {
            fwrite(STDERR, "Swap rollback left temporary or backup residue.\n");
            exit(91);
        }
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $harness,
        repo_path('bin/orbit-session-archive-filesystem.php'),
        $workspace,
    ], repo_path());
    $process->run();

    return $process;
}

function run_session_archive_unexpected_final_harness(string $workspace): Process
{
    $harness = <<<'PHP'
        declare(strict_types=1);

        require $argv[1];

        $workspace = $argv[2];
        $archiveDir = "{$workspace}/archive-final";
        $temporaryArchiveDir = "{$workspace}/.archive-final.tmp-test";
        $backupDir = "{$workspace}/.archive-final.backup-test";
        mkdir($temporaryArchiveDir);
        file_put_contents("{$temporaryArchiveDir}/new-final.txt", "new final\n");
        $expectedArchiveIdentity = function_exists('orbitSessionArchivePathIdentity')
            ? orbitSessionArchivePathIdentity($archiveDir)
            : 'absent';

        mkdir($archiveDir);
        file_put_contents("{$archiveDir}/unexpected-sentinel.txt", "unexpected final\n");
        $failure = null;

        try {
            swapArchiveDirectories(
                $temporaryArchiveDir,
                $archiveDir,
                $backupDir,
                null,
                $expectedArchiveIdentity,
            );
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        if ($failure === null || ! str_contains(strtolower($failure), 'unexpected')) {
            fwrite(STDERR, "Unexpected final was not rejected explicitly.\n");
            exit(92);
        }

        if (file_get_contents("{$archiveDir}/unexpected-sentinel.txt") !== "unexpected final\n") {
            fwrite(STDERR, "Unexpected final was mutated.\n");
            exit(93);
        }

        if (file_exists($temporaryArchiveDir) || file_exists($backupDir)) {
            fwrite(STDERR, "Unexpected-final rejection did not clean only the completed temp.\n");
            exit(94);
        }
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $harness,
        repo_path('bin/orbit-session-archive-filesystem.php'),
        $workspace,
    ], repo_path());
    $process->run();

    return $process;
}

function run_session_archive_rollback_failure_harness(string $workspace): Process
{
    $harness = <<<'PHP'
        declare(strict_types=1);

        require $argv[1];

        $workspace = $argv[2];
        $archiveDir = "{$workspace}/archive-final";
        $temporaryArchiveDir = "{$workspace}/.archive-final.tmp-test";
        $backupDir = "{$workspace}/.archive-final.backup-test";
        mkdir($archiveDir);
        mkdir($temporaryArchiveDir);
        file_put_contents("{$archiveDir}/previous-final.txt", "old final\n");
        file_put_contents("{$temporaryArchiveDir}/new-final.txt", "new final\n");

        $renameCalls = [];
        $rename = static function (string $source, string $destination) use (&$renameCalls): bool {
            $renameCalls[] = [$source, $destination];

            if (count($renameCalls) > 1) {
                return false;
            }

            return rename($source, $destination);
        };
        $failure = null;

        try {
            swapArchiveDirectories($temporaryArchiveDir, $archiveDir, $backupDir, $rename);
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        if (count($renameCalls) !== 3 || $failure === null) {
            fwrite(STDERR, "Activation and rollback failures were not both exercised.\n");
            exit(95);
        }

        if (file_exists($archiveDir)) {
            fwrite(STDERR, "Failed rollback unexpectedly restored a final archive.\n");
            exit(96);
        }

        if (
            ! is_file("{$temporaryArchiveDir}/new-final.txt")
            || ! is_file("{$backupDir}/previous-final.txt")
        ) {
            fwrite(STDERR, "Failed rollback did not retain complete temp and old backup.\n");
            exit(97);
        }

        if (! str_contains($failure, $temporaryArchiveDir) || ! str_contains($failure, $backupDir)) {
            fwrite(STDERR, "Failed rollback did not report both retained recovery paths.\n");
            exit(98);
        }
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $harness,
        repo_path('bin/orbit-session-archive-filesystem.php'),
        $workspace,
    ], repo_path());
    $process->run();

    return $process;
}

function run_session_archive_no_final_activation_failure_harness(string $workspace): Process
{
    $harness = <<<'PHP'
        declare(strict_types=1);

        require $argv[1];

        $workspace = $argv[2];
        $archiveDir = "{$workspace}/archive-final";
        $temporaryArchiveDir = "{$workspace}/.archive-final.tmp-test";
        $backupDir = "{$workspace}/.archive-final.backup-test";
        mkdir($temporaryArchiveDir);
        file_put_contents("{$temporaryArchiveDir}/new-final.txt", "new final\n");
        $expectedArchiveIdentity = orbitSessionArchivePathIdentity($archiveDir);
        $renameCalls = 0;
        $rename = static function () use (&$renameCalls): bool {
            $renameCalls++;

            return false;
        };
        $failure = null;

        try {
            swapArchiveDirectories(
                $temporaryArchiveDir,
                $archiveDir,
                $backupDir,
                $rename,
                $expectedArchiveIdentity,
            );
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        if ($expectedArchiveIdentity !== 'absent' || $renameCalls !== 1 || $failure === null) {
            fwrite(STDERR, "Absent-final activation failure was not isolated.\n");
            exit(99);
        }

        if (! is_file("{$temporaryArchiveDir}/new-final.txt")) {
            fwrite(STDERR, "Absent-final activation failure deleted the complete temp.\n");
            exit(100);
        }

        if (file_exists($archiveDir) || file_exists($backupDir)) {
            fwrite(STDERR, "Absent-final activation failure created final or backup state.\n");
            exit(101);
        }

        $lowerFailure = strtolower($failure);

        if (
            ! str_contains($failure, $temporaryArchiveDir)
            || str_contains($failure, $backupDir)
            || ! str_contains($lowerFailure, 'no previous final or backup existed')
            || str_contains($lowerFailure, 'rollback')
            || str_contains($lowerFailure, 'rolled back')
            || str_contains($lowerFailure, 'restor')
        ) {
            fwrite(STDERR, "Absent-final recovery message was inaccurate: {$failure}\n");
            exit(102);
        }
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $harness,
        repo_path('bin/orbit-session-archive-filesystem.php'),
        $workspace,
    ], repo_path());
    $process->run();

    return $process;
}

/**
 * @return array<string, mixed>
 */
function session_archive_summary(Process $process): array
{
    return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function session_archive_directories(string $archiveRoot): array
{
    $directories = array_map(
        'basename',
        array_filter(glob("{$archiveRoot}/*") ?: [], 'is_dir'),
    );

    sort($directories, SORT_STRING);

    return array_values($directories);
}

function remove_session_archive_workspace(string $path): void
{
    if ($path === '' || ! str_contains($path, '/orbit-session-archive-')) {
        return;
    }

    new Process(['rm', '-rf', $path])->run();
}

/**
 * Snapshot .orbit/agent-sessions tree as [relativePath => sha1(content)] for exact comparison.
 * Returns sorted assoc array so identical trees compare equal.
 */
function snapshot_agent_sessions_tree(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $snapshot = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $rel = substr($full, strlen($root) + 1);
        $content = (string) file_get_contents($full);
        $snapshot[$rel] = sha1($content);
    }

    ksort($snapshot);

    return $snapshot;
}

it(
    'session archive prefers staged agent-sessions from lane-close capture and fallback adds/overwrites nothing (exact tree match)',
    function (): void {
        $workspace = session_archive_workspace(suffix: 'staged-prefer-exact');

        try {
            $paths = session_archive_paths(workspace: $workspace);

            // Seed a staged capture dir in the active .orbit (as lane-close capture would)
            $stagedRoot = $paths['sourceOrbitDir'].'/agent-sessions';
            $stagedProvider = "{$stagedRoot}/codex/staged-lane-801";
            mkdir($stagedProvider, recursive: true);
            file_put_contents("{$stagedProvider}/manifest.json", json_encode([
                'schema_version' => 1,
                'provider' => 'codex',
                'status' => 'ok',
                'slug' => 'staged-lane-801',
                'solo_process_id' => 801,
                'marker_match' => 'exact',
            ], JSON_THROW_ON_ERROR)
                .PHP_EOL);
            file_put_contents("{$stagedProvider}/usage.json", json_encode([
                'input_tokens' => 11,
                'output_tokens' => 3,
            ], JSON_THROW_ON_ERROR)
                .PHP_EOL);
            file_put_contents("{$stagedProvider}/messages.jsonl", json_encode([
                'role' => 'user',
                'content' => 'staged marker content',
            ])
                .PHP_EOL);
            mkdir("{$stagedProvider}/raw", recursive: true);
            file_put_contents("{$stagedProvider}/raw/rollout.jsonl", "{}\n");

            // Also put a minimal loop.md referencing a process
            file_put_contents($paths['loopPath'], "# Orbit Current Slice State\n\n## Evidence Links\n- process 801\n");

            // Snapshot the exact staged tree (relative paths + content hashes) BEFORE archive
            $beforeSnapshot = snapshot_agent_sessions_tree($stagedRoot);

            $process = run_session_archive(arguments: [
                "--source-orbit-dir={$paths['sourceOrbitDir']}",
                "--archive-root={$paths['archiveRoot']}",
                '--timestamp=2026-07-07-101010',
                '--slug=staged-prefer-exact',
                "--cwd={$paths['cwd']}",
                "--home={$paths['home']}",
            ]);

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            $summary = session_archive_summary(process: $process);
            $archiveAgentDir = $summary['archive_dir'].'/agent-sessions';

            // Snapshot AFTER
            $afterSnapshot = snapshot_agent_sessions_tree($archiveAgentDir);

            // The archive agent-sessions must be byte-identical to what was staged (no fallback added files, no overwrites, no top-level manifest.json from live extraction, no extra dirs)
            expect($afterSnapshot)->toBe(
                $beforeSnapshot,
                'archive agent-sessions tree must exactly match staged source; fallback must not add/overwrite anything',
            );

            // Explicitly assert no fallback-created top-level manifest or unknown providers appeared
            expect("{$archiveAgentDir}/manifest.json")->not->toBeFile();
            expect("{$archiveAgentDir}/unknown")->not->toBeDirectory();
        } finally {
            remove_session_archive_workspace(path: $workspace);
        }
    },
);

it('session archive excludes direct provider temp and backup residue without deleting it', function (): void {
    $workspace = session_archive_workspace(suffix: 'foreign-temp-excluded');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $agentSessionsRoot = $paths['sourceOrbitDir'].'/agent-sessions/codex';
        $stagedProvider = "{$agentSessionsRoot}/valid-lane-801";
        $foreignTemp = "{$agentSessionsRoot}/.valid-lane-801.tmp-foreign";
        $retainedBackup = "{$agentSessionsRoot}/.valid-lane-801.backup-retained";
        mkdir($stagedProvider, recursive: true);
        mkdir($foreignTemp, recursive: true);
        mkdir($retainedBackup, recursive: true);
        file_put_contents("{$stagedProvider}/manifest.json", json_encode([
            'schema_version' => 1,
            'provider' => 'codex',
            'status' => 'ok',
            'slug' => 'valid-lane-801',
            'solo_process_id' => 801,
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);
        file_put_contents("{$stagedProvider}/usage.json", "{}\n");
        file_put_contents("{$stagedProvider}/messages.jsonl", "{}\n");
        mkdir("{$stagedProvider}/raw", recursive: true);
        file_put_contents("{$stagedProvider}/raw/rollout.jsonl", "{}\n");
        file_put_contents("{$foreignTemp}/manifest.json", json_encode([
            'status' => 'ok',
            'slug' => 'foreign-temp',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);
        file_put_contents("{$foreignTemp}/sentinel.txt", "preserve\n");
        file_put_contents("{$retainedBackup}/sentinel.txt", "backup preserve\n");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-07-111111',
            '--slug=foreign-temp-excluded',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain($foreignTemp)
            ->toContain(basename($retainedBackup))
            ->and("{$foreignTemp}/sentinel.txt")
            ->toBeFile()
            ->and(file_get_contents("{$foreignTemp}/sentinel.txt"))
            ->toBe("preserve\n")
            ->and("{$retainedBackup}/sentinel.txt")
            ->toBeFile()
            ->and(file_get_contents("{$retainedBackup}/sentinel.txt"))
            ->toBe("backup preserve\n");

        $summary = session_archive_summary(process: $process);

        expect("{$summary['archive_dir']}/agent-sessions/codex/valid-lane-801/manifest.json")
            ->toBeFile()
            ->and("{$summary['archive_dir']}/agent-sessions/codex/.valid-lane-801.tmp-foreign")
            ->not->toBeDirectory()->and(
                "{$summary['archive_dir']}/agent-sessions/codex/.valid-lane-801.backup-retained/sentinel.txt",
            )
            ->not->toBeFile();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive does not treat a lone foreign temp capture as valid staging', function (): void {
    $workspace = session_archive_workspace(suffix: 'lone-foreign-temp-fallback');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $foreignTemp = $paths['sourceOrbitDir'].'/agent-sessions/codex/.lane.tmp-foreign';
        mkdir($foreignTemp, recursive: true);
        file_put_contents("{$foreignTemp}/manifest.json", json_encode([
            'status' => 'ok',
            'slug' => 'foreign-temp',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);
        file_put_contents("{$foreignTemp}/sentinel.txt", "preserve\n");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-07-121212',
            '--slug=lone-foreign-temp-fallback',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain($foreignTemp)
            ->not
            ->toContain('Preferring validated staged captures')
            ->and("{$foreignTemp}/sentinel.txt")
            ->toBeFile();

        $summary = session_archive_summary(process: $process);

        expect("{$summary['archive_dir']}/agent-sessions/manifest.json")
            ->toBeFile()
            ->and("{$summary['archive_dir']}/agent-sessions/codex/.lane.tmp-foreign")
            ->not->toBeDirectory();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('root review preserves unrelated temp and backup shaped evidence directories byte for byte', function (): void {
    $workspace = session_archive_workspace(suffix: 'unrelated-temp-evidence');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $evidenceTemp = $paths['sourceOrbitDir'].'/evidence/.report.tmp-kept';
        $evidenceBackup = $paths['sourceOrbitDir'].'/evidence/.report.backup-kept';
        mkdir($evidenceTemp, recursive: true);
        mkdir($evidenceBackup, recursive: true);
        file_put_contents("{$evidenceTemp}/sentinel.txt", "preserve evidence\n");
        file_put_contents("{$evidenceBackup}/sentinel.txt", "preserve backup evidence\n");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-07-131313',
            '--slug=unrelated-temp-evidence',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->not->toContain("excluding foreign temporary capture directory: {$evidenceTemp}");

        $summary = session_archive_summary(process: $process);
        $archivedSentinel = "{$summary['archive_dir']}/evidence/.report.tmp-kept/sentinel.txt";
        $archivedBackup = "{$summary['archive_dir']}/evidence/.report.backup-kept/sentinel.txt";

        expect($archivedSentinel)
            ->toBeFile()
            ->and(file_get_contents($archivedSentinel))
            ->toBe("preserve evidence\n")
            ->and("{$evidenceTemp}/sentinel.txt")
            ->toBeFile()
            ->and($archivedBackup)
            ->toBeFile()
            ->and(file_get_contents($archivedBackup))
            ->toBe("preserve backup evidence\n")
            ->and("{$evidenceBackup}/sentinel.txt")
            ->toBeFile();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('root review never follows an agent session directory symlink for copy or staged discovery', function (): void {
    $workspace = session_archive_workspace(suffix: 'agent-session-directory-symlink');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $providerRoot = $paths['sourceOrbitDir'].'/agent-sessions/codex';
        $external = "{$workspace}/external-capture";
        $linkedCapture = "{$providerRoot}/linked-capture";
        mkdir($providerRoot, recursive: true);
        mkdir($external, recursive: true);
        file_put_contents("{$external}/manifest.json", json_encode([
            'status' => 'ok',
            'slug' => 'external-capture',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);
        file_put_contents("{$external}/sentinel.txt", "external preserve\n");
        symlink($external, $linkedCapture);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-07-141414',
            '--slug=agent-session-directory-symlink',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->not->toContain('Preferring validated staged captures');

        $summary = session_archive_summary(process: $process);

        expect("{$summary['archive_dir']}/agent-sessions/manifest.json")
            ->toBeFile()
            ->and("{$summary['archive_dir']}/agent-sessions/codex/linked-capture")
            ->not
            ->toBeDirectory()
            ->and("{$external}/sentinel.txt")
            ->toBeFile()
            ->and(file_get_contents("{$external}/sentinel.txt"))
            ->toBe("external preserve\n");
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('late re-review never follows a root agent sessions symlink for copy or staged discovery', function (): void {
    $workspace = session_archive_workspace(suffix: 'root-agent-sessions-symlink');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $external = "{$workspace}/external-agent-sessions";
        $rootLink = $paths['sourceOrbitDir'].'/agent-sessions';
        mkdir($external, recursive: true);
        file_put_contents("{$external}/manifest.json", json_encode([
            'status' => 'ok',
            'slug' => 'external-root',
        ], JSON_THROW_ON_ERROR)
            .PHP_EOL);
        file_put_contents("{$external}/sentinel.txt", "external root preserve\n");
        symlink($external, $rootLink);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-07-151515',
            '--slug=root-agent-sessions-symlink',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getErrorOutput())
            ->not->toContain('Preferring validated staged captures');

        $summary = session_archive_summary(process: $process);

        expect("{$summary['archive_dir']}/agent-sessions/manifest.json")
            ->toBeFile()
            ->and("{$summary['archive_dir']}/agent-sessions/sentinel.txt")
            ->not
            ->toBeFile()
            ->and("{$external}/sentinel.txt")
            ->toBeFile()
            ->and(file_get_contents("{$external}/sentinel.txt"))
            ->toBe("external root preserve\n")
            ->and($rootLink)
            ->toBeDirectory()
            ->and(is_link($rootLink))
            ->toBeTrue();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects a symlinked archive root before mutating its target', function (): void {
    $workspace = session_archive_workspace(suffix: 'symlinked-archive-root');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveRootTarget = "{$workspace}/archive-root-target";
        mkdir($archiveRootTarget);
        file_put_contents("{$archiveRootTarget}/sentinel.txt", "preserve\n");
        symlink($archiveRootTarget, $paths['archiveRoot']);

        $beforeSnapshot = snapshot_session_archive_tree($archiveRootTarget);
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100000',
            '--slug=symlinked-archive-root',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('archive root')
            ->toContain('symlink')
            ->and(snapshot_session_archive_tree($archiveRootTarget))
            ->toBe($beforeSnapshot);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects a symlinked explicit destination before mutating its target', function (): void {
    $workspace = session_archive_workspace(suffix: 'symlinked-archive-destination');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveDir = "{$paths['archiveRoot']}/2026-07-10-100001-symlinked-destination";
        $archiveTarget = "{$workspace}/archive-target";
        mkdir($paths['archiveRoot']);
        mkdir($archiveTarget);
        file_put_contents("{$archiveTarget}/sentinel.txt", "preserve\n");
        symlink($archiveTarget, $archiveDir);

        $beforeSnapshot = snapshot_session_archive_tree($archiveTarget);
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            "--archive-dir={$archiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('archive directory')
            ->toContain('must not be a symlink')
            ->and(snapshot_session_archive_tree($archiveTarget))
            ->toBe($beforeSnapshot);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects a symlinked source orbit root before archive construction: :dataset', function (
    string $sourceSuffix,
): void {
    $workspace = session_archive_workspace(suffix: 'symlinked-source-root');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $sourceOrbitTarget = "{$paths['cwd']}/.orbit-real";
        rename($paths['sourceOrbitDir'], $sourceOrbitTarget);
        mkdir("{$sourceOrbitTarget}/sub");
        symlink($sourceOrbitTarget, $paths['sourceOrbitDir']);
        $sourceOrbitArgument = $paths['sourceOrbitDir'].$sourceSuffix;
        $activeLoopBefore = file_get_contents("{$sourceOrbitTarget}/loop.md");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$sourceOrbitArgument}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100002',
            '--slug=symlinked-source-root',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not->toBe(0)->and(strtolower($process->getErrorOutput()))->toContain('source .orbit')->toContain(
                'symlink',
            )->and($paths['archiveRoot'])
            ->not->toBeDirectory()->and("{$sourceOrbitTarget}/evidence/proof.txt")->toBeFile()->and(file_get_contents(
                "{$sourceOrbitTarget}/loop.md",
            ))->toBe($activeLoopBefore);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
})->with([
    'direct symlink spelling' => [''],
    'dot-suffix symlink spelling' => ['/.'],
    'nested parent spelling' => ['/sub/..'],
]);

it('session archive requires an explicit destination to be a direct child of the explicit archive root', function (): void {
    $workspace = session_archive_workspace(suffix: 'explicit-containment');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $outsideArchiveDir = "{$workspace}/outside/2026-07-10-100003-containment";

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            "--archive-dir={$outsideArchiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not->toBe(0)->and(strtolower($process->getErrorOutput()))->toContain('direct child')->toContain(
                'archive root',
            )->and($outsideArchiveDir)
            ->not->toBeDirectory();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive uses the canonical parent of an explicit destination when no archive root is supplied', function (): void {
    $workspace = session_archive_workspace(suffix: 'explicit-parent');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveDir = "{$workspace}/explicit-parent/2026-07-10-100004-explicit-parent";

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-dir={$archiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(session_archive_summary(process: $process))
            ->toHaveKey('archive_dir', $archiveDir)
            ->and($archiveDir)
            ->toBeDirectory()
            ->and(dirname($archiveDir).'/index.json')
            ->toBeFile();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive construction failure preserves the old final and leaves no transaction residue', function (): void {
    $workspace = session_archive_workspace(suffix: 'construction-rollback');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveDir = seed_existing_session_archive(
            paths: $paths,
            basename: '2026-07-10-100005-construction-rollback',
        );
        mkdir("{$paths['sourceOrbitDir']}/orbit-session-archive.json");
        file_put_contents("{$paths['sourceOrbitDir']}/orbit-session-archive.json/collision.txt", "collision\n");

        $oldFinalSnapshot = snapshot_session_archive_tree($archiveDir);
        $activeLoopBefore = (string) file_get_contents($paths['loopPath']);
        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-dir={$archiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and($process->getErrorOutput())
            ->toContain('orbit-session-archive.json')
            ->and(snapshot_session_archive_tree($archiveDir))
            ->toBe($oldFinalSnapshot)
            ->and(session_archive_transaction_residue($archiveDir))
            ->toBe([])
            ->and(file_get_contents($paths['loopPath']))
            ->toBe($activeLoopBefore);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive filesystem helper rolls the old final back when the second rename fails', function (): void {
    $workspace = session_archive_workspace(suffix: 'swap-rollback');

    try {
        $process = run_session_archive_swap_harness(workspace: $workspace);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive filesystem helper rejects a source swap before copying bytes', function (): void {
    $workspace = session_archive_workspace(suffix: 'copy-source-swap');

    try {
        $process = run_session_archive_copy_swap_harness($workspace);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive filesystem helper rejects an unexpected final without mutating it', function (): void {
    $workspace = session_archive_workspace(suffix: 'unexpected-final');

    try {
        $process = run_session_archive_unexpected_final_harness(workspace: $workspace);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive filesystem helper retains temp and backup when activation rollback fails', function (): void {
    $workspace = session_archive_workspace(suffix: 'rollback-failure');

    try {
        $process = run_session_archive_rollback_failure_harness(workspace: $workspace);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive filesystem helper retains temp when activation fails without a prior final', function (): void {
    $workspace = session_archive_workspace(suffix: 'no-final-activation-failure');

    try {
        $process = run_session_archive_no_final_activation_failure_harness(workspace: $workspace);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput());
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive retains the coherent new final and old backup when index refresh fails after swap', function (): void {
    $workspace = session_archive_workspace(suffix: 'post-swap-index-failure');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveDir = seed_existing_session_archive(
            paths: $paths,
            basename: '2026-07-10-100007-post-swap-index-failure',
        );
        $oldFinalSnapshot = snapshot_session_archive_tree($archiveDir);
        mkdir("{$paths['archiveRoot']}/index.json");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-dir={$archiveDir}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);
        $backupDirectories = session_archive_backup_directories($archiveDir);
        $errorOutput = $process->getErrorOutput();

        expect($process->getExitCode())
            ->not->toBe(0)->and($backupDirectories)->toHaveCount(1)->and(snapshot_session_archive_tree(
                $backupDirectories[0],
            ))->toBe($oldFinalSnapshot)->and("{$archiveDir}/evidence/proof.txt")->toBeFile()->and(
                "{$archiveDir}/previous-final.txt",
            )->toBeFile()->and("{$archiveDir}/orbit-session-archive.json")->toBeFile()->and(file_get_contents(
                "{$archiveDir}/loop.md",
            ))->toBe(file_get_contents($paths['loopPath']))->and(strtolower($process->getErrorOutput()))->toContain(
                'index recovery',
            )->and($errorOutput)->toContain($archiveDir)->toContain($backupDirectories[0])->toContain(
                'bin/orbit-session-index',
            )->toContain('--write')
            ->not->toContain(' mv ')
            ->not->toContain('restore')->and(
                session_archive_temp_directories($archiveDir),
            )->toBe([]);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive retains recovery state when the active loop write fails after swap', function (): void {
    $workspace = session_archive_workspace(suffix: 'post-swap-loop-failure');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $archiveDir = seed_existing_session_archive(
            paths: $paths,
            basename: '2026-07-10-100008-post-swap-loop-failure',
        );
        $oldFinalSnapshot = snapshot_session_archive_tree($archiveDir);
        $activeLoopBefore = (string) file_get_contents($paths['loopPath']);

        chmod($paths['loopPath'], 0o444);
        chmod($paths['sourceOrbitDir'], 0o555);

        try {
            $process = run_session_archive(arguments: [
                "--source-orbit-dir={$paths['sourceOrbitDir']}",
                "--archive-dir={$archiveDir}",
                "--cwd={$paths['cwd']}",
                "--home={$paths['home']}",
            ]);
        } finally {
            chmod($paths['sourceOrbitDir'], 0o775);
            chmod($paths['loopPath'], 0o664);
        }

        $backupDirectories = session_archive_backup_directories($archiveDir);

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and($backupDirectories)
            ->toHaveCount(1)
            ->and(snapshot_session_archive_tree($backupDirectories[0]))
            ->toBe($oldFinalSnapshot)
            ->and("{$archiveDir}/evidence/proof.txt")
            ->toBeFile()
            ->and("{$archiveDir}/orbit-session-archive.json")
            ->toBeFile()
            ->and(file_get_contents($paths['loopPath']))
            ->toBe($activeLoopBefore)
            ->and(strtolower($process->getErrorOutput()))
            ->toContain('recovery')
            ->and($process->getErrorOutput())
            ->toContain($archiveDir)
            ->toContain($backupDirectories[0])
            ->toContain('mv');
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects invalid staged manifests without falling back: :dataset', function (
    array $manifestOverrides,
    ?string $rawManifest,
    string $expectedError,
    array $missingKeys = [],
): void {
    $workspace = session_archive_workspace(suffix: 'invalid-staged-manifest');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $manifest = array_replace(valid_staged_manifest(), $manifestOverrides);

        foreach ($missingKeys as $missingKey) {
            unset($manifest[$missingKey]);
        }

        $captureDir = stage_session_archive_capture(
            paths: $paths,
            manifest: $rawManifest ?? $manifest,
        );
        $sourceManifestPath = realpath("{$captureDir}/manifest.json");
        $activeLoopBefore = (string) file_get_contents($paths['loopPath']);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100009',
            '--slug=invalid-staged-manifest',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        $canonicalArchiveRoot = realpath($paths['archiveRoot']);

        expect($sourceManifestPath)->toBeString()->and($canonicalArchiveRoot)->toBeString();

        $temporaryManifestPattern =
            '#'
            .preg_quote($canonicalArchiveRoot, '#')
            .'/\.2026-07-10-100009-invalid-staged-manifest\.tmp-[a-f0-9]{16}'
            .'/agent-sessions/codex/lane-801/manifest\.json#';

        expect($process->getExitCode())
            ->not->toBe(0)->and($process->getErrorOutput())->toContain($expectedError)->toMatch(
                $temporaryManifestPattern,
            )
            ->not->toContain($sourceManifestPath)
            ->not->toContain(
                'agent-sessions will contain an empty manifest',
            )->and(session_archive_transaction_directories($paths['archiveRoot']))->toBe([])->and(file_get_contents(
                $paths['loopPath'],
            ))->toBe($activeLoopBefore);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
})->with([
    'invalid JSON' => [[], '{invalid-json', 'valid JSON'],
    'unknown failed status' => [['status' => 'failed', 'reason' => 'generic failure'], null, "unknown status 'failed'"],
    'missing status' => [[], null, 'status is required', ['status']],
    'missing failure reason' => [['status' => 'capture_failed'], null, 'non-empty reason'],
    'schema v2' => [['schema_version' => 2], null, 'schema_version must be 1'],
    'zero process id' => [['solo_process_id' => 0], null, 'positive integer'],
    'negative process id' => [['solo_process_id' => -1], null, 'positive integer'],
    'string process id' => [['solo_process_id' => '801'], null, 'positive integer'],
    'whitespace failure reason' => [['status' => 'capture_failed', 'reason' => " \t\n"], null, 'non-empty reason'],
    'provider path mismatch' => [['provider' => 'claude'], null, "provider must match directory 'codex'"],
    'slug path mismatch' => [['slug' => 'other-lane'], null, "slug must match directory 'lane-801'"],
]);

it('session archive rejects a manifestless direct staged capture before fallback', function (): void {
    $workspace = session_archive_workspace(suffix: 'manifestless-staged-capture');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $captureDir = "{$paths['sourceOrbitDir']}/agent-sessions/codex/lane-801";
        mkdir($captureDir, recursive: true);
        file_put_contents("{$captureDir}/sentinel.txt", "source capture preserved\n");
        $activeLoopBefore = file_get_contents($paths['loopPath']);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100014',
            '--slug=manifestless-staged-capture',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not->toBe(0)->and($process->getErrorOutput())->toContain('manifest.json')->toContain('required')
            ->not->toContain('agent-sessions will contain an empty manifest')->and(
                "{$captureDir}/sentinel.txt",
            )->toBeFile()->and(file_get_contents("{$captureDir}/sentinel.txt"))->toBe(
                "source capture preserved\n",
            )->and(file_get_contents($paths['loopPath']))->toBe($activeLoopBefore)->and(
                session_archive_directories($paths['archiveRoot']),
            )->toBe([])->and(session_archive_transaction_directories($paths['archiveRoot']))->toBe([]);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

it('session archive rejects incomplete ok staged artifacts: :dataset', function (
    string $artifactState,
    string $expectedError,
): void {
    $workspace = session_archive_workspace(suffix: "incomplete-ok-{$artifactState}");

    try {
        $paths = session_archive_paths(workspace: $workspace);
        stage_session_archive_capture(
            paths: $paths,
            manifest: valid_staged_manifest(),
            artifactState: $artifactState,
        );

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100010',
            "--slug=incomplete-ok-{$artifactState}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->not->toBe(0)->and($process->getErrorOutput())->toContain($expectedError)
            ->not->toContain(
                'agent-sessions will contain an empty manifest',
            )->and(session_archive_transaction_directories($paths['archiveRoot']))->toBe([]);
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
})->with([
    'empty usage' => ['empty-usage', 'usage.json must be non-empty'],
    'empty messages' => ['empty-messages', 'messages.jsonl must be non-empty'],
    'empty raw tree' => ['empty-raw', 'raw must contain a non-empty regular file'],
    'symlink-only raw tree' => ['raw-symlink-only', 'raw must contain a non-empty regular file'],
]);

it('session archive accepts closed staged failure evidence: :dataset', function (string $status): void {
    $workspace = session_archive_workspace(suffix: "accepted-staged-{$status}");

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $manifest = array_replace(valid_staged_manifest(), [
            'status' => $status,
            'reason' => "{$status} evidence retained",
        ]);
        $captureDir = stage_session_archive_capture(
            paths: $paths,
            manifest: $manifest,
            artifactState: 'none',
        );

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100011',
            "--slug=accepted-staged-{$status}",
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('Preferring validated staged captures')
            ->and(
                session_archive_summary(process: $process)['archive_dir']
                .'/agent-sessions/codex/lane-801/manifest.json',
            )
            ->toBeFile()
            ->and(file_get_contents($captureDir.'/manifest.json'))
            ->toBe(file_get_contents(
                session_archive_summary(process: $process)['archive_dir']
                .'/agent-sessions/codex/lane-801/manifest.json',
            ));
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
})->with([
    'capture_failed',
    'partial',
]);

it('session archive warns and skips source file symlinks without following them: :dataset', function (bool $nested): void {
    $workspace = session_archive_workspace(suffix: $nested ? 'nested-file-symlink' : 'root-file-symlink');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $externalFile = "{$workspace}/external-secret.txt";
        $relativeArchivePath = $nested ? 'evidence/linked-secret.txt' : 'linked-secret.txt';
        $sourceLink = "{$paths['sourceOrbitDir']}/{$relativeArchivePath}";
        file_put_contents($externalFile, "must not be archived\n");
        symlink($externalFile, $sourceLink);

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100012',
            '--slug=file-symlink-no-follow',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain(basename($sourceLink));

        $archiveDir = session_archive_summary(process: $process)['archive_dir'];

        expect("{$archiveDir}/{$relativeArchivePath}")
            ->not
            ->toBeFile()
            ->and($externalFile)
            ->toBeFile()
            ->and(file_get_contents($externalFile))
            ->toBe("must not be archived\n");
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
})->with([
    'root file symlink' => [false],
    'nested file symlink' => [true],
]);

it('session archive excludes release candidates and reports only copied top-level source entries', function (): void {
    $workspace = session_archive_workspace(suffix: 'truthful-copied-entries');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $releaseCandidates = "{$paths['sourceOrbitDir']}/release-candidates";
        $priorSessions = "{$paths['sourceOrbitDir']}/sessions";
        $externalFile = "{$workspace}/external.txt";
        mkdir($releaseCandidates);
        mkdir($priorSessions);
        file_put_contents("{$releaseCandidates}/candidate.json", "{}\n");
        file_put_contents("{$priorSessions}/prior.txt", "prior\n");
        file_put_contents($externalFile, "external\n");
        symlink($externalFile, "{$paths['sourceOrbitDir']}/linked-root-file.txt");

        $process = run_session_archive(arguments: [
            "--source-orbit-dir={$paths['sourceOrbitDir']}",
            "--archive-root={$paths['archiveRoot']}",
            '--timestamp=2026-07-10-100013',
            '--slug=truthful-copied-entries',
            "--cwd={$paths['cwd']}",
            "--home={$paths['home']}",
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = session_archive_summary(process: $process);
        $archiveDir = $summary['archive_dir'];

        expect($summary['copied_entries'])
            ->toBe(['agent-sessions', 'evidence', 'loop.md'])
            ->and("{$archiveDir}/release-candidates")
            ->not->toBeDirectory()->and("{$archiveDir}/sessions")
            ->not->toBeDirectory()->and("{$archiveDir}/linked-root-file.txt")
            ->not->toBeFile()->and("{$releaseCandidates}/candidate.json")->toBeFile();
    } finally {
        remove_session_archive_workspace(path: $workspace);
    }
});

/**
 * @param array{sourceOrbitDir: string, archiveRoot: string, loopPath: string} $paths
 */
function seed_existing_session_archive(array $paths, string $basename): string
{
    $archiveDir = "{$paths['archiveRoot']}/{$basename}";
    mkdir($archiveDir, recursive: true);
    copy($paths['loopPath'], "{$archiveDir}/loop.md");
    file_put_contents("{$archiveDir}/previous-final.txt", "old final\n");

    return $archiveDir;
}

/**
 * @return array<string, string>
 */
function snapshot_session_archive_tree(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relativePath = substr($entry->getPathname(), strlen($root) + 1);

        if ($entry->isLink()) {
            $snapshot["link:{$relativePath}"] = (string) readlink($entry->getPathname());

            continue;
        }

        if ($entry->isDir()) {
            $snapshot["dir:{$relativePath}"] = '';

            continue;
        }

        if ($entry->isFile()) {
            $snapshot["file:{$relativePath}"] = hash_file('sha256', $entry->getPathname());
        }
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * @return list<string>
 */
function session_archive_transaction_residue(string $archiveDir): array
{
    return [
        ...session_archive_temp_directories($archiveDir),
        ...session_archive_backup_directories($archiveDir),
    ];
}

/**
 * @return list<string>
 */
function session_archive_temp_directories(string $archiveDir): array
{
    return session_archive_matching_directories($archiveDir, 'tmp');
}

/**
 * @return list<string>
 */
function session_archive_backup_directories(string $archiveDir): array
{
    return session_archive_matching_directories($archiveDir, 'backup');
}

/**
 * @return list<string>
 */
function session_archive_matching_directories(string $archiveDir, string $kind): array
{
    $matches = array_values(array_filter(
        glob(dirname($archiveDir).'/.'.basename($archiveDir).".{$kind}-*") ?: [],
        'is_dir',
    ));
    sort($matches, SORT_STRING);

    return $matches;
}

/**
 * @return list<string>
 */
function session_archive_transaction_directories(string $archiveRoot): array
{
    if (! is_dir($archiveRoot)) {
        return [];
    }

    $directories = [];

    foreach (new FilesystemIterator($archiveRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir()) {
            $directories[] = $entry->getPathname();
        }
    }

    sort($directories, SORT_STRING);

    return $directories;
}

/**
 * @return array{schema_version: int, provider: string, status: string, slug: string, solo_process_id: int}
 */
function valid_staged_manifest(): array
{
    return [
        'schema_version' => 1,
        'provider' => 'codex',
        'status' => 'ok',
        'slug' => 'lane-801',
        'solo_process_id' => 801,
    ];
}

/**
 * @param array{sourceOrbitDir: string} $paths
 * @param array<string, mixed>|string $manifest
 */
function stage_session_archive_capture(array $paths, array|string $manifest, string $artifactState = 'complete'): string
{
    $captureDir = $paths['sourceOrbitDir'].'/agent-sessions/codex/lane-801';
    mkdir($captureDir, recursive: true);
    $manifestContents = is_array($manifest)
        ? json_encode($manifest, JSON_THROW_ON_ERROR).PHP_EOL
        : $manifest;
    file_put_contents("{$captureDir}/manifest.json", $manifestContents);

    if ($artifactState === 'none') {
        return $captureDir;
    }

    file_put_contents("{$captureDir}/usage.json", $artifactState === 'empty-usage' ? '' : "{}\n");
    file_put_contents("{$captureDir}/messages.jsonl", $artifactState === 'empty-messages' ? '' : "{}\n");
    mkdir("{$captureDir}/raw");

    if ($artifactState === 'raw-symlink-only') {
        $externalRawFile = dirname($paths['sourceOrbitDir']).'/external-raw.jsonl';
        file_put_contents($externalRawFile, "{}\n");
        symlink($externalRawFile, "{$captureDir}/raw/rollout.jsonl");
    } elseif ($artifactState !== 'empty-raw') {
        file_put_contents("{$captureDir}/raw/rollout.jsonl", "{}\n");
    }

    return $captureDir;
}
