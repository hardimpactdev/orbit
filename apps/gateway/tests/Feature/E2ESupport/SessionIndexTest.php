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
            ->toHaveKey('fresh_analyzer_verdict', 'final reassessment VERDICT: yes')
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

it('normalizes accepted same-line and nested packet shapes for facets', function (): void {
    $workspace = session_index_workspace('facet-normalization');

    try {
        $sessionsDir = "{$workspace}/sessions";

        $packetTemplate = <<<'MD'
            # Orbit Current Slice State

            ## Blockers

            %s

            ## Final Distillation

            - Loop outcome: %s
            - Fresh analyzer: %s
            MD;

        $cases = [
            'same-line' => [
                'blockers' => '- none',
                'outcome' => 'complete.',
                'analyzer' => 'not used - compact loop, no analyzer run',
                'expected' => [
                    'loop_outcome' => 'complete',
                    'loop_outcome_raw' => 'complete.',
                    'fresh_analyzer_verdict' => 'not used',
                    'fresh_analyzer_verdict_raw' => 'not used - compact loop, no analyzer run',
                    'blockers_present' => false,
                ],
            ],
            'natural-no-blocker' => [
                'blockers' => 'No active correctness blocker remains.',
                'outcome' => 'complete + loop improvement',
                'analyzer' => 'not applicable.',
                'expected' => [
                    'loop_outcome' => 'complete + loop improvement',
                    'fresh_analyzer_verdict' => 'not used',
                    'fresh_analyzer_verdict_raw' => 'not applicable.',
                    'blockers_present' => false,
                ],
            ],
            'deferred' => [
                'blockers' => '- none',
                'outcome' => 'blocked',
                'analyzer' => 'deferred - needs follow up',
                'expected' => [
                    'loop_outcome' => 'blocked',
                    'fresh_analyzer_verdict' => 'deferred',
                    'fresh_analyzer_verdict_raw' => 'deferred - needs follow up',
                ],
            ],
            'backtick' => [
                'blockers' => '- none',
                'outcome' => '`complete + loop improvement`.',
                'analyzer' => 'not used - compact loop',
                'expected' => [
                    'loop_outcome' => 'complete + loop improvement',
                    'loop_outcome_raw' => '`complete + loop improvement`.',
                ],
            ],
            'blocked-reason' => [
                'blockers' => '- none',
                'outcome' => 'blocked - retained topology unavailable',
                'analyzer' => 'not used',
                'expected' => [
                    'loop_outcome' => 'blocked',
                    'loop_outcome_raw' => 'blocked - retained topology unavailable',
                ],
            ],
            'skipped-because' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'skipped because no escalation',
                'expected' => [
                    'fresh_analyzer_verdict' => 'not used',
                    'fresh_analyzer_verdict_raw' => 'skipped because no escalation',
                ],
            ],
            'skipped-for' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'skipped for analyzer lane',
                'expected' => [
                    'fresh_analyzer_verdict' => 'not used',
                    'fresh_analyzer_verdict_raw' => 'skipped for analyzer lane',
                ],
            ],
            'verdict-same' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: yes',
                'expected' => [
                    'fresh_analyzer_verdict' => 'yes',
                    'fresh_analyzer_verdict_raw' => 'Verdict: yes',
                ],
            ],
            'bare-yes' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'yes',
                'expected' => [
                    'fresh_analyzer_verdict' => 'yes',
                    'fresh_analyzer_verdict_raw' => 'yes',
                ],
            ],
            'verdict-yes-trailing-punctuation' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: yes.',
                'expected' => [
                    'fresh_analyzer_verdict' => 'yes',
                    'fresh_analyzer_verdict_raw' => 'Verdict: yes.',
                ],
            ],
            'verdict-proper-rationale' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: proper; no missed verification',
                'expected' => [
                    'fresh_analyzer_verdict' => 'proper',
                    'fresh_analyzer_verdict_raw' => 'Verdict: proper; no missed verification',
                ],
            ],
            'verdict-flawed-rationale' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: flawed - partial evidence',
                'expected' => [
                    'fresh_analyzer_verdict' => 'flawed',
                    'fresh_analyzer_verdict_raw' => 'Verdict: flawed - partial evidence',
                ],
            ],
            'verdict-blocked-missing' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: blocked-by-missing-evidence',
                'expected' => [
                    'fresh_analyzer_verdict' => 'blocked-by-missing-evidence',
                    'fresh_analyzer_verdict_raw' => 'Verdict: blocked-by-missing-evidence',
                ],
            ],
            'verdict-spaced-blocked-missing-rationale' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: blocked by missing evidence because capture is absent',
                'expected' => [
                    'fresh_analyzer_verdict' => 'blocked-by-missing-evidence',
                    'fresh_analyzer_verdict_raw' => 'Verdict: blocked by missing evidence because capture is absent',
                ],
            ],
            'yes-comma-rationale-prose' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'yes, because the loop was complete',
                'expected' => [
                    'fresh_analyzer_verdict' => 'yes, because the loop was complete',
                    'fresh_analyzer_verdict_raw' => 'yes, because the loop was complete',
                ],
            ],
            'verdict-no-semicolon-rationale-prose' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'Verdict: no; documented reason',
                'expected' => [
                    'fresh_analyzer_verdict' => 'Verdict: no; documented reason',
                    'fresh_analyzer_verdict_raw' => 'Verdict: no; documented reason',
                ],
            ],
            'whole-proper' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'proper',
                'expected' => [
                    'fresh_analyzer_verdict' => 'proper',
                    'fresh_analyzer_verdict_raw' => 'proper',
                ],
            ],
            'whole-no' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'no',
                'expected' => [
                    'fresh_analyzer_verdict' => 'no',
                    'fresh_analyzer_verdict_raw' => 'no',
                ],
            ],
            'spaced-blocked-missing' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'blocked by missing evidence',
                'expected' => [
                    'fresh_analyzer_verdict' => 'blocked-by-missing-evidence',
                    'fresh_analyzer_verdict_raw' => 'blocked by missing evidence',
                ],
            ],
            'head-flawed' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'flawed - partial evidence',
                'expected' => [
                    'fresh_analyzer_verdict' => 'flawed',
                    'fresh_analyzer_verdict_raw' => 'flawed - partial evidence',
                ],
            ],
            'head-blocked-missing' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'blocked-by-missing-evidence',
                'expected' => [
                    'fresh_analyzer_verdict' => 'blocked-by-missing-evidence',
                    'fresh_analyzer_verdict_raw' => 'blocked-by-missing-evidence',
                ],
            ],
            'embedded-yes-prose' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'final reassessment VERDICT: yes - loop quality proper',
                'expected' => [
                    'fresh_analyzer_verdict' => 'final reassessment VERDICT: yes - loop quality proper',
                    'fresh_analyzer_verdict_raw' => 'final reassessment VERDICT: yes - loop quality proper',
                ],
            ],
            'verdict-no-with-rationale-prose' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'VERDICT: no - documented reason',
                'expected' => [
                    'fresh_analyzer_verdict' => 'VERDICT: no - documented reason',
                    'fresh_analyzer_verdict_raw' => 'VERDICT: no - documented reason',
                ],
            ],
            'replay-proper-no-new' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'loop quality proper; no new signal',
                'expected' => [
                    'fresh_analyzer_verdict' => 'loop quality proper; no new signal',
                    'fresh_analyzer_verdict_raw' => 'loop quality proper; no new signal',
                ],
            ],
            'replay-no-sensible' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'no sensible actionable findings; all previous findings verified fixed.',
                'expected' => [
                    'fresh_analyzer_verdict' => 'no sensible actionable findings; all previous findings verified fixed.',
                    'fresh_analyzer_verdict_raw' => 'no sensible actionable findings; all previous findings verified fixed.',
                ],
            ],
            'replay-no-blockers' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'No blockers.',
                'expected' => [
                    'fresh_analyzer_verdict' => 'No blockers.',
                    'fresh_analyzer_verdict_raw' => 'No blockers.',
                ],
            ],
            'replay-accept-no-missed' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'accept; no missed verification; no durable signal worth capturing; app-first scheduling is separate deferred work',
                'expected' => [
                    'fresh_analyzer_verdict' => 'accept; no missed verification; no durable signal worth capturing; app-first scheduling is separate deferred work',
                    'fresh_analyzer_verdict_raw' => 'accept; no missed verification; no durable signal worth capturing; app-first scheduling is separate deferred work',
                ],
            ],
            'replay-no-analyzer-verdict' => [
                'blockers' => '- none',
                'outcome' => 'complete',
                'analyzer' => 'no analyzer verdict',
                'expected' => [
                    'fresh_analyzer_verdict' => 'no analyzer verdict',
                    'fresh_analyzer_verdict_raw' => 'no analyzer verdict',
                ],
            ],
        ];

        foreach ([
            'none-dot' => '- None.',
            'no-blockers-bare' => '- No blockers.',
            'no-blocker-todo190' => '- No blocker for Solo todo #190.',
            'no-blocker-todo191' => '- No blocker for Solo todo #191.',
            'no-active-remains-resolved' => '- No active implementation or verification blocker remains. The earlier blocker is resolved.',
            'multiple-resolved-blockers' => "- No active implementation blocker remains.\n- The previous analyzer evidence blocker is resolved.",
        ] as $slug => $blockers) {
            $cases[$slug] = [
                'blockers' => $blockers,
                'outcome' => 'complete',
                'analyzer' => 'not used - baseline',
                'expected' => ['blockers_present' => false],
            ];
        }

        foreach ([
            'none-currently' => '- none currently',
            'none-currently-reviewer' => '- None currently. The reviewer lane was replaced ...',
            'none-currently-future' => '- none currently. Possible future blocker: ...',
            'no-blocker-currently' => '- No blocker currently.',
            'safety-control' => '- No blocker was resolved, but deployment remains blocked.',
            'safety-none-but' => '- None currently, but deployment remains blocked.',
            'mixed-blockers' => "- none\n- deployment remains blocked",
            'none-semi-however' => '- none; however a latent risk remains.',
            'none-period-yet' => '- none. Yet follow-up work is required.',
            'none-emdash-block' => '- none — deployment still gated on prior finding.',
            'no-blockers-but-continuation' => '- No blockers. However the prior signal was not addressed.',
        ] as $slug => $blockers) {
            $cases[$slug] = [
                'blockers' => $blockers,
                'outcome' => 'complete',
                'analyzer' => 'not used - baseline',
                'expected' => ['blockers_present' => true],
            ];
        }

        $caseNumber = 0;

        foreach ($cases as $slug => $case) {
            session_index_archive(
                sessionsDir: $sessionsDir,
                basename: sprintf('2026-07-10-1000%02d-%s', $caseNumber, $slug),
                loop: sprintf($packetTemplate, $case['blockers'], $case['outcome'], $case['analyzer']),
            );

            $caseNumber++;
        }

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100020-nested',
            loop: session_index_loop(
                outcome: 'complete',
                analyzerVerdict: 'skipped.',
                verificationRows: ['Retained topology proof' => 'not applicable'],
                blockers: ['none'],
            ),
        );

        $indented = <<<'MD'
            # Orbit Current Slice State

            ## Blockers

            - none

            ## Final Distillation

              - Loop outcome: complete
              - Fresh analyzer: not used
            - Some label: value here
              - Loop outcome: complete
              - Fresh analyzer: not used
            MD;

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100021-indented',
            loop: $indented,
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        foreach ($cases as $slug => $case) {
            expect(session_index_record($index, $slug))->toMatchArray($case['expected']);
        }

        expect(session_index_record($index, 'nested'))
            ->toMatchArray([
                'loop_outcome' => 'complete',
                'fresh_analyzer_verdict' => 'not used',
                'fresh_analyzer_verdict_raw' => 'skipped.',
                'blockers_present' => false,
            ]);

        expect(session_index_record($index, 'indented'))
            ->toHaveKey('loop_outcome', 'unknown')
            ->toHaveKey('loop_outcome_raw', null)
            ->toHaveKey('fresh_analyzer_verdict', 'unknown')
            ->toHaveKey('fresh_analyzer_verdict_raw', null);
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
