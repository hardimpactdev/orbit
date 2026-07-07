<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('writes and checks deterministic facets for heterogeneous session archives', function (): void {
    $workspace = session_index_workspace('facets');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-06-26-122115-legacy-slice',
            loop: session_index_loop(
                outcome: 'complete',
                analyzerVerdict: null,
                verificationRows: [
                    'Retained topology proof' => 'not applicable - docs only',
                    '`composer quality-check`' => 'unknown',
                ],
                candidateSignals: [
                    'Legacy missing labels -> unknown -> parse tolerance',
                ],
                blockers: [
                    'none',
                ],
            ),
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-07-101537-lane-close-agent-session-capture',
            loop: session_index_loop(
                outcome: 'complete + loop improvement',
                analyzerVerdict: 'final reassessment VERDICT: yes',
                verificationRows: [
                    'Retained topology proof' => 'not applicable - repo harness tooling',
                    '`composer quality-check`' => 'passed - artifact .orbit/quality-gates/quality-check.json',
                    '`composer quality-gate:final-check`' => 'blocked - not part of this slice',
                ],
                candidateSignals: [
                    'Lane-close agent-session capture loss -> promote -> recurring evidence loss',
                    'Reviewer gate finding -> already-covered -> fixed directly',
                    'Marker shape mismatch -> reject -> implementation detail',
                ],
                blockers: [
                    'none',
                ],
            ),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'lane-close-capture-reviewer-802',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 100,
                        'output_tokens' => 25,
                        'total_tokens' => 125,
                        'reasoning_tokens' => 5,
                    ],
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'lane-close-capture-worker-801',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 300,
                        'output_tokens' => 75,
                        'total_tokens' => 375,
                        'reasoning_tokens' => 15,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-07-120000-empty-capture',
            loop: session_index_loop(outcome: 'blocked'),
            aggregateManifest: [
                'schema_version' => 1,
                'sessions' => [],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-07-121500-partial-capture',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'ok-lane',
                    'status' => 'ok',
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'missing-lane',
                    'status' => 'solo_process_not_found',
                ],
            ],
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect($index)
            ->toHaveKey('schema_version', 1)
            ->toHaveKey('record_count', 4);

        $legacy = session_index_record($index, 'legacy-slice');
        $ok = session_index_record($index, 'lane-close-agent-session-capture');
        $empty = session_index_record($index, 'empty-capture');
        $partial = session_index_record($index, 'partial-capture');

        expect($legacy)
            ->toHaveKey('timestamp', '2026-06-26-122115')
            ->toHaveKey('capture_status', 'legacy')
            ->toHaveKey('fresh_analyzer_verdict', 'unknown')
            ->and($legacy['required_verification']['Retained topology proof'])
            ->toBe('not applicable')
            ->and($legacy['required_verification']['`composer quality-check`'])
            ->toBe('unknown');

        expect($ok)
            ->toHaveKey('capture_status', 'ok')
            ->toHaveKey('loop_outcome', 'complete + loop improvement')
            ->toHaveKey('fresh_analyzer_verdict', 'yes')
            ->toHaveKey('blockers_present', false)
            ->and($ok['required_verification']['`composer quality-gate:final-check`'])
            ->toBe('blocked')
            ->and($ok['candidate_signals'])
            ->toMatchArray([
                'total' => 3,
                'classifications' => [
                    'promote' => 1,
                    'already-covered' => 1,
                    'reject' => 1,
                ],
            ])
            ->and($ok['token_usage'])
            ->toMatchArray([
                'input_tokens' => 400,
                'output_tokens' => 100,
                'total_tokens' => 500,
                'reasoning_tokens' => 20,
            ]);

        expect($empty)->toHaveKey('capture_status', 'empty');
        expect($partial)->toHaveKey('capture_status', 'partial');

        $check = run_session_index($sessionsDir, ['--check']);

        expect($check->getExitCode())->toBe(0, $check->getErrorOutput());
    } finally {
        session_index_remove($workspace);
    }
});

it('fails check mode when the committed session index is stale', function (): void {
    $workspace = session_index_workspace('drift');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-01-100305-drift-check',
            loop: session_index_loop(outcome: 'complete'),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        file_put_contents("{$sessionsDir}/index.json", json_encode(['stale' => true], JSON_THROW_ON_ERROR).PHP_EOL);

        $check = run_session_index($sessionsDir, ['--check']);

        expect($check->getExitCode())
            ->toBe(1)
            ->and($check->getErrorOutput())
            ->toContain('Session index is stale');
    } finally {
        session_index_remove($workspace);
    }
});

it('refreshes the session index when session archive creates and refreshes an archive', function (): void {
    $workspace = session_index_workspace('archive-hook');

    try {
        $sourceOrbitDir = "{$workspace}/active/.orbit";
        $archiveRoot = "{$workspace}/sessions";

        mkdir("{$sourceOrbitDir}/evidence", recursive: true);
        file_put_contents("{$sourceOrbitDir}/evidence/proof.txt", "proof\n");
        file_put_contents("{$sourceOrbitDir}/loop.md", session_index_loop(outcome: 'complete'));

        $create = run_session_archive_for_index(
            $sourceOrbitDir,
            $archiveRoot,
            [
                '--timestamp=2026-07-07-123000',
                '--slug=archive-hook',
            ],
        );

        expect($create->getExitCode())->toBe(0, $create->getErrorOutput());
        expect(run_session_index($archiveRoot, ['--check'])->getExitCode())->toBe(0);

        $refresh = run_session_archive_for_index(
            $sourceOrbitDir,
            $archiveRoot,
            [
                '--slug=archive-hook',
            ],
        );

        expect($refresh->getExitCode())->toBe(0, $refresh->getErrorOutput());
        expect(run_session_index($archiveRoot, ['--check'])->getExitCode())->toBe(0);
    } finally {
        session_index_remove($workspace);
    }
});

function run_session_index(string $sessionsDir, array $arguments = []): Process
{
    $process = new Process([
        session_index_repo_path('bin/orbit-session-index'),
        "--sessions-dir={$sessionsDir}",
        ...$arguments,
    ], session_index_repo_path());

    $process->run();

    return $process;
}

function run_session_archive_for_index(string $sourceOrbitDir, string $archiveRoot, array $arguments): Process
{
    $process = new Process([
        session_index_repo_path('bin/orbit-session-archive'),
        "--source-orbit-dir={$sourceOrbitDir}",
        "--archive-root={$archiveRoot}",
        '--cwd='.dirname($sourceOrbitDir),
        '--home='.dirname(dirname($sourceOrbitDir)),
        ...$arguments,
    ], session_index_repo_path());

    $process->run();

    return $process;
}

function session_index_repo_path(?string $path = null): string
{
    $root = dirname(__DIR__, 5);

    if ($path === null) {
        return $root;
    }

    return "{$root}/{$path}";
}

function session_index_workspace(string $suffix): string
{
    $workspace = sys_get_temp_dir().'/orbit-session-index-test-'.getmypid().'-'.$suffix.'-'.bin2hex(random_bytes(3));

    mkdir($workspace, recursive: true);

    return $workspace;
}

function session_index_archive(
    string $sessionsDir,
    string $basename,
    string $loop,
    array $captures = [],
    ?array $aggregateManifest = null,
): void {
    $archiveDir = "{$sessionsDir}/{$basename}";

    mkdir($archiveDir, recursive: true);
    file_put_contents("{$archiveDir}/loop.md", $loop);

    if ($aggregateManifest !== null) {
        mkdir("{$archiveDir}/agent-sessions", recursive: true);
        file_put_contents(
            "{$archiveDir}/agent-sessions/manifest.json",
            json_encode($aggregateManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    foreach ($captures as $capture) {
        $sessionDir = "{$archiveDir}/agent-sessions/{$capture['provider']}/{$capture['slug']}";

        mkdir($sessionDir, recursive: true);
        file_put_contents(
            "{$sessionDir}/manifest.json",
            json_encode(
                [
                    'schema_version' => 1,
                    'provider' => $capture['provider'],
                    'slug' => $capture['slug'],
                    'status' => $capture['status'],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )
                .PHP_EOL,
        );

        if (isset($capture['usage'])) {
            file_put_contents(
                "{$sessionDir}/usage.json",
                json_encode($capture['usage'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    .PHP_EOL,
            );
        }
    }
}

function session_index_loop(
    string $outcome,
    ?string $analyzerVerdict = null,
    array $verificationRows = [],
    array $candidateSignals = [],
    array $blockers = [],
): string {
    $verification = '';

    foreach ($verificationRows as $label => $value) {
        $verification .= "  - {$label}: {$value}\n";
    }

    $signals = '';

    foreach ($candidateSignals as $signal) {
        $signals .= "  - {$signal}\n";
    }

    $blockerText = '';

    foreach ($blockers as $blocker) {
        $blockerText .= "- {$blocker}\n";
    }

    $analyzer = $analyzerVerdict === null ? '' : "  - Verdict: {$analyzerVerdict}\n";

    return <<<MARKDOWN
        # Orbit Current Slice State

        ## Blockers

        {$blockerText}
        ## Final Distillation

        - Loop outcome:
          - {$outcome}
        - Required verification:
        {$verification}- Fresh analyzer:
          - Persona: .agents/review-personas/post-feature-analyzer.md
        {$analyzer}- Candidate signals:
        {$signals}- Accepted durable updates:
          - none
        - Rejected or already-covered signals:
          - none
        - Deferred follow-ups:
          - none
        - No-new-signal rationale:
          - none

        MARKDOWN;
}

function session_index_json(string $sessionsDir): array
{
    return json_decode((string) file_get_contents("{$sessionsDir}/index.json"), true, flags: JSON_THROW_ON_ERROR);
}

function session_index_record(array $index, string $slug): array
{
    foreach ($index['records'] as $record) {
        if ($record['slug'] === $slug) {
            return $record;
        }
    }

    throw new RuntimeException("Missing session index record for slug [{$slug}].");
}

function session_index_remove(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
