<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

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
 * @param list<string> $arguments
 */
function run_session_archive(array $arguments): Process
{
    $process = new Process(
        [repo_path('bin/orbit-session-archive'), ...$arguments],
        repo_path(),
        [
            'SOLO_PROCESS_ID' => false,
            'SOLO_PROJECT_ID' => false,
        ],
    );

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

it('session archive excludes and warns about foreign temp capture siblings without deleting them', function (): void {
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
            ->not
            ->toContain("excluding foreign temporary capture directory: {$retainedBackup}")
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
            ->not
            ->toBeDirectory()
            ->and("{$summary['archive_dir']}/agent-sessions/codex/.valid-lane-801.backup-retained/sentinel.txt")
            ->toBeFile()
            ->and(file_get_contents(
                "{$summary['archive_dir']}/agent-sessions/codex/.valid-lane-801.backup-retained/sentinel.txt",
            ))
            ->toBe("backup preserve\n");
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

it('root review preserves unrelated temp-shaped evidence directories byte for byte', function (): void {
    $workspace = session_archive_workspace(suffix: 'unrelated-temp-evidence');

    try {
        $paths = session_archive_paths(workspace: $workspace);
        $evidenceTemp = $paths['sourceOrbitDir'].'/evidence/.report.tmp-kept';
        mkdir($evidenceTemp, recursive: true);
        file_put_contents("{$evidenceTemp}/sentinel.txt", "preserve evidence\n");

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

        expect($archivedSentinel)
            ->toBeFile()
            ->and(file_get_contents($archivedSentinel))
            ->toBe("preserve evidence\n")
            ->and("{$evidenceTemp}/sentinel.txt")
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
