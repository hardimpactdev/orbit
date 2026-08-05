<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('indexes compact loop receipts alongside historical archives', function (): void {
    $workspace = session_index_workspace('compact-mixed');

    try {
        $sessionsDir = "{$workspace}/sessions";
        session_index_archive(
            $sessionsDir,
            '2026-07-10-180000-compact-loop',
            session_index_compact_loop(),
        );
        $compactDir = "{$sessionsDir}/2026-07-10-180000-compact-loop";
        file_put_contents(
            "{$compactDir}/orbit-session-archive.json",
            json_encode([
                'schema_version' => 2,
                'archive_mode' => 'compact',
                'copied_entries' => ['feedback.jsonl', 'loop.md'],
            ], JSON_THROW_ON_ERROR)
                .PHP_EOL,
        );
        file_put_contents("{$compactDir}/feedback.jsonl", implode("\n", [
            json_encode([
                'schema_version' => 1,
                'type' => 'feedback.recorded',
                'id' => 'feedback-1',
                'recorded_at' => '2026-07-10T18:00:00Z',
                'raw_text' => 'Progress froze.',
                'session_ref' => 'codex://threads/example#feedback',
                'candidate_commit' => str_repeat('a', 40),
                'surface' => 'cli.progress',
                'context' => [],
                'evidence' => [],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'schema_version' => 1,
                'type' => 'feedback.promoted',
                'id' => 'promotion-1',
                'recorded_at' => '2026-07-10T18:01:00Z',
                'feedback_id' => 'feedback-1',
                'scope' => 'cli.progress',
                'expectation' => 'Keep progress monotonic.',
                'protection' => [
                    'kind' => 'test',
                    'reference' => 'bin/quality-check-progress-frame-check',
                    'rejected_example' => 'feedback-1',
                    'accepted_example' => 'monotonic-progress',
                ],
            ], JSON_THROW_ON_ERROR),
            '',
        ]));
        session_index_archive(
            $sessionsDir,
            '2026-07-09-120000-historical-loop',
            session_index_loop(outcome: 'complete'),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);
        $compact = session_index_record($index, 'compact-loop');
        $historical = session_index_record($index, 'historical-loop');

        expect($index)
            ->toHaveKey('schema_version', 3)
            ->and($compact)
            ->toMatchArray([
                'schema' => 'compact-v1',
                'state' => 'accepted',
                'review_status' => 'passed',
                'blast_radius_status' => 'complete',
                'acceptance_status' => 'accepted',
                'acceptance_venue' => 'automated',
                'feedback_count' => 2,
                'archive_mode' => 'compact',
            ])
            ->and($compact['feedback'])
            ->toMatchArray([
                'status' => 'valid',
                'raw_count' => 1,
                'promoted_count' => 1,
                'waived_count' => 0,
                'protection_failures' => 0,
            ])
            ->and($historical)
            ->toHaveKey('schema', 'legacy')
            ->toHaveKey('archive_mode', 'legacy')
            ->toHaveKey('loop_outcome', 'complete');
    } finally {
        session_index_remove($workspace);
    }
});

it('reports malformed compact feedback as invalid instead of silently ignoring it', function (): void {
    $workspace = session_index_workspace('invalid-feedback');

    try {
        $sessionsDir = "{$workspace}/sessions";
        session_index_archive(
            $sessionsDir,
            '2026-07-10-181000-invalid-feedback',
            session_index_compact_loop(),
        );
        file_put_contents(
            "{$sessionsDir}/2026-07-10-181000-invalid-feedback/feedback.jsonl",
            "{not-json}\n",
        );

        $write = run_session_index($sessionsDir, ['--write']);
        $record = session_index_record(session_index_json($sessionsDir), 'invalid-feedback');

        expect($write->getExitCode())
            ->toBe(0, $write->getErrorOutput())
            ->and($record['feedback'])
            ->toMatchArray([
                'status' => 'invalid',
                'event_count' => null,
                'raw_count' => null,
            ])
            ->and($record['feedback_count'])
            ->toBeNull();
    } finally {
        session_index_remove($workspace);
    }
});

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
            ->toHaveKey('schema_version', 3)
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

it('classifies token usage without inventing missing or invalid values', function (): void {
    $workspace = session_index_workspace('token-usage-status');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100000-unavailable-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'no-usage-file',
                    'status' => 'ok',
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100100-consistent-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'first-consistent-file',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 10,
                        'output_tokens' => 2,
                        'total_tokens' => 12,
                        'reasoning_tokens' => 1,
                    ],
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'second-consistent-file',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 20,
                        'output_tokens' => 3,
                        'total_tokens' => 23,
                        'reasoning_tokens' => 4,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100200-partial-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'complete-contributor',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 30,
                        'output_tokens' => 5,
                        'total_tokens' => 35,
                        'reasoning_tokens' => 6,
                    ],
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'missing-reasoning',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 40,
                        'output_tokens' => 7,
                        'total_tokens' => 47,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100300-malformed-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'malformed-json',
                    'status' => 'ok',
                    'usage' => [],
                ],
            ],
        );
        file_put_contents(
            "{$sessionsDir}/2026-07-10-100300-malformed-token-usage/agent-sessions/codex/malformed-json/usage.json",
            '{',
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100400-non-integer-token-component',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'non-integer',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => '10',
                        'output_tokens' => 1,
                        'total_tokens' => 11,
                        'reasoning_tokens' => 0,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100450-negative-token-component',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'negative',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 10,
                        'output_tokens' => -1,
                        'total_tokens' => 9,
                        'reasoning_tokens' => 0,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100500-inconsistent-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'mismatched-total',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 50,
                        'output_tokens' => 8,
                        'total_tokens' => 99,
                        'reasoning_tokens' => 3,
                    ],
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'matched-total',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 60,
                        'output_tokens' => 9,
                        'total_tokens' => 69,
                        'reasoning_tokens' => 4,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100600-inconsistent-partial-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'codex',
                    'slug' => 'inconsistent-contributor',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 70,
                        'output_tokens' => 10,
                        'total_tokens' => 100,
                        'reasoning_tokens' => 5,
                    ],
                ],
                [
                    'provider' => 'grok',
                    'slug' => 'partial-contributor',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 80,
                        'output_tokens' => 11,
                        'total_tokens' => 91,
                    ],
                ],
            ],
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-100700-invalid-precedence-token-usage',
            loop: session_index_loop(outcome: 'complete'),
            captures: [
                [
                    'provider' => 'grok',
                    'slug' => 'invalid-contributor',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => -1,
                        'output_tokens' => 1,
                        'total_tokens' => 0,
                        'reasoning_tokens' => 0,
                    ],
                ],
                [
                    'provider' => 'codex',
                    'slug' => 'inconsistent-contributor',
                    'status' => 'ok',
                    'usage' => [
                        'input_tokens' => 90,
                        'output_tokens' => 12,
                        'total_tokens' => 120,
                    ],
                ],
            ],
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);
        $unavailable = session_index_record($index, 'unavailable-token-usage');
        $consistent = session_index_record($index, 'consistent-token-usage');
        $partial = session_index_record($index, 'partial-token-usage');
        $malformed = session_index_record($index, 'malformed-token-usage');
        $nonInteger = session_index_record($index, 'non-integer-token-component');
        $negative = session_index_record($index, 'negative-token-component');
        $inconsistent = session_index_record($index, 'inconsistent-token-usage');
        $inconsistentPartial = session_index_record($index, 'inconsistent-partial-token-usage');
        $invalidPrecedence = session_index_record($index, 'invalid-precedence-token-usage');

        expect($index)
            ->toHaveKey('schema_version', 3)
            ->and($unavailable)
            ->not
            ->toHaveKey('token_usage_status')
            ->and($unavailable['token_usage'])
            ->toBe([
                'status' => 'unavailable',
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'reasoning_tokens' => null,
            ])
            ->and($consistent['token_usage'])
            ->toBe([
                'status' => 'consistent',
                'input_tokens' => 30,
                'output_tokens' => 5,
                'total_tokens' => 35,
                'reasoning_tokens' => 5,
            ])
            ->and($partial['token_usage'])
            ->toBe([
                'status' => 'partial',
                'input_tokens' => 70,
                'output_tokens' => 12,
                'total_tokens' => null,
                'reasoning_tokens' => null,
            ]);

        foreach ([$malformed, $nonInteger, $negative, $invalidPrecedence] as $invalid) {
            expect($invalid['token_usage'])->toBe([
                'status' => 'invalid',
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'reasoning_tokens' => null,
            ]);
        }

        expect($inconsistent['token_usage'])
            ->toBe([
                'status' => 'inconsistent',
                'input_tokens' => 110,
                'output_tokens' => 17,
                'total_tokens' => 168,
                'reasoning_tokens' => 7,
            ])
            ->and($inconsistentPartial['token_usage'])
            ->toBe([
                'status' => 'inconsistent',
                'input_tokens' => 150,
                'output_tokens' => 21,
                'total_tokens' => null,
                'reasoning_tokens' => null,
            ]);
    } finally {
        session_index_remove($workspace);
    }
});

it('serializes empty and populated candidate classifications as JSON objects', function (): void {
    $workspace = session_index_workspace('candidate-classification-shape');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110000-empty-classifications',
            loop: session_index_loop(outcome: 'complete'),
        );
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110100-populated-classifications',
            loop: session_index_loop(
                outcome: 'complete + loop improvement',
                candidateSignals: [
                    'First signal -> promote -> durable correction',
                    'Second signal -> reject -> local-only issue',
                ],
            ),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $rawIndex = (string) file_get_contents("{$sessionsDir}/index.json");
        $objectIndex = json_decode($rawIndex, false, 512, JSON_THROW_ON_ERROR);
        $records = [];

        foreach ($objectIndex->records as $record) {
            $records[$record->slug] = $record;
        }

        expect($records['empty-classifications']->legacy->candidate_signals->classifications)
            ->toBeInstanceOf(stdClass::class)
            ->and((array) $records['empty-classifications']->legacy->candidate_signals->classifications)
            ->toBe([])
            ->and($records['populated-classifications']->legacy->candidate_signals->classifications)
            ->toBeInstanceOf(stdClass::class)
            ->and((array) $records['populated-classifications']->legacy->candidate_signals->classifications)
            ->toBe([
                'promote' => 1,
                'reject' => 1,
            ]);
    } finally {
        session_index_remove($workspace);
    }
});

it('distinguishes invalid empty and partial aggregate capture manifests', function (): void {
    $workspace = session_index_workspace('aggregate-capture-status');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120000-malformed-aggregate',
            loop: session_index_loop(outcome: 'complete'),
        );
        mkdir("{$sessionsDir}/2026-07-10-120000-malformed-aggregate/agent-sessions", recursive: true);
        file_put_contents(
            "{$sessionsDir}/2026-07-10-120000-malformed-aggregate/agent-sessions/manifest.json",
            '{',
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120100-unusable-aggregate',
            loop: session_index_loop(outcome: 'complete'),
            aggregateManifest: [
                'schema_version' => 1,
                'sessions' => 'not-an-array',
            ],
        );
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120150-object-shaped-sessions',
            loop: session_index_loop(outcome: 'complete'),
        );
        mkdir("{$sessionsDir}/2026-07-10-120150-object-shaped-sessions/agent-sessions", recursive: true);
        file_put_contents(
            "{$sessionsDir}/2026-07-10-120150-object-shaped-sessions/agent-sessions/manifest.json",
            <<<'JSON'
                {
                    "schema_version": 1,
                    "sessions": {}
                }
                JSON,
        );
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120200-empty-aggregate',
            loop: session_index_loop(outcome: 'complete'),
            aggregateManifest: [
                'schema_version' => 1,
                'sessions' => [],
            ],
        );
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120300-partial-aggregate',
            loop: session_index_loop(outcome: 'complete'),
            aggregateManifest: [
                'schema_version' => 1,
                'sessions' => [
                    [
                        'provider' => 'codex',
                        'status' => 'ok',
                    ],
                ],
            ],
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect(session_index_record($index, 'malformed-aggregate'))
            ->toHaveKey('capture_status', 'invalid')
            ->and(session_index_record($index, 'unusable-aggregate'))
            ->toHaveKey('capture_status', 'invalid')
            ->and(session_index_record($index, 'object-shaped-sessions'))
            ->toHaveKey('capture_status', 'invalid')
            ->and(session_index_record($index, 'empty-aggregate'))
            ->toHaveKey('capture_status', 'empty')
            ->and(session_index_record($index, 'partial-aggregate'))
            ->toHaveKey('capture_status', 'partial');
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
            'none-currently' => '- none currently',
            'no-blocker-currently' => '- No blocker currently.',
        ] as $slug => $blockers) {
            $cases[$slug] = [
                'blockers' => $blockers,
                'outcome' => 'complete',
                'analyzer' => 'not used - baseline',
                'expected' => ['blockers_present' => false],
            ];
        }

        foreach ([
            'none-currently-reviewer' => '- None currently. The reviewer lane was replaced ...',
            'none-currently-future' => '- none currently. Possible future blocker: ...',
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

it('uses explicit nested analyzer verdict provenance for precedence and rationale normalization', function (): void {
    $workspace = session_index_workspace('analyzer-verdict-provenance');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $packetTemplate = <<<'MD'
            # Orbit Current Slice State

            ## Blockers

            - none

            ## Final Distillation

            - Loop outcome: complete
            - Fresh analyzer: %s
              - Persona: .agents/review-personas/post-feature-analyzer.md
              - Verdict: %s
            MD;

        $cases = [
            'dash-rationale' => [
                'same_line' => 'passed - Solo Codex process `956`, `VERDICT: yes`; capture retained.',
                'verdict' => 'yes - no findings; analyzer confirmed no blocking packet gaps.',
                'expected_raw' => 'yes - no findings; analyzer confirmed no blocking packet gaps.',
                'expected' => 'yes',
            ],
            'semicolon-rationale' => [
                'same_line' => 'completed with analyzer evidence',
                'verdict' => '`yes`; no implementation, contract, verification, topology, evidence, packet, or guardrail correction required.',
                'expected_raw' => '`yes`; no implementation, contract, verification, topology, evidence, packet, or guardrail correction required.',
                'expected' => 'yes',
            ],
            'backticked-verdict-rationale' => [
                'same_line' => 'initial blocked result before final reassessment `VERDICT: yes`',
                'verdict' => '`VERDICT: yes` - no findings; worker correction accepted.',
                'expected_raw' => '`VERDICT: yes` - no findings; worker correction accepted.',
                'expected' => 'yes',
            ],
            'no-blockers-is-not-no' => [
                'same_line' => 'completed with analyzer evidence',
                'verdict' => 'No blockers',
                'expected_raw' => 'No blockers',
                'expected' => 'No blockers',
            ],
            'no-rationale' => [
                'same_line' => 'completed with analyzer evidence',
                'verdict' => 'no - documented reason',
                'expected_raw' => 'no - documented reason',
                'expected' => 'no',
            ],
            'equivalent-not-used-keeps-existing-raw-facet' => [
                'same_line' => 'not used - no implementation diff existed to analyze; separately adjudicated.',
                'verdict' => 'not used',
                'expected_raw' => 'not used - no implementation diff existed to analyze; separately adjudicated.',
                'expected' => 'not used',
            ],
            'equivalent-yes-keeps-existing-raw-facet' => [
                'same_line' => 'Verdict: yes',
                'verdict' => 'yes - final rationale',
                'expected_raw' => 'Verdict: yes',
                'expected' => 'yes',
            ],
        ];

        foreach ($cases as $slug => $case) {
            session_index_archive(
                sessionsDir: $sessionsDir,
                basename: "2026-07-10-110000-{$slug}",
                loop: sprintf($packetTemplate, $case['same_line'], $case['verdict']),
            );
        }

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110001-direct-child-verdict',
            loop: <<<'MD'
                # Orbit Current Slice State

                ## Blockers

                - none

                ## Final Distillation

                - Loop outcome: complete
                - Fresh analyzer: completed with analyzer evidence
                  - Prior review:
                    - Verdict: no - stale
                  - Verdict: yes - final
                MD,
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110002-same-line-prose',
            loop: <<<'MD'
                # Orbit Current Slice State

                ## Blockers

                - none

                ## Final Distillation

                - Loop outcome: complete
                - Fresh analyzer: yes - explanation
                MD,
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110003-grandchild-verdict',
            loop: <<<'MD'
                # Orbit Current Slice State

                ## Blockers

                - none

                ## Final Distillation

                - Loop outcome: complete
                - Fresh analyzer:
                  - Prior review:
                    - Verdict: yes
                MD,
        );

        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-110004-prose-verdict',
            loop: <<<'MD'
                # Orbit Current Slice State

                ## Blockers

                - none

                ## Final Distillation

                - Loop outcome: complete
                - Fresh analyzer:
                  Analyzer prose containing VERDICT: yes is not authoritative.
                MD,
        );

        expect(run_session_index($sessionsDir, ['--write'])->getExitCode())->toBe(0);

        $index = session_index_json($sessionsDir);

        foreach ($cases as $slug => $case) {
            expect(session_index_record($index, $slug))
                ->toHaveKey('fresh_analyzer_verdict_raw', $case['expected_raw'])
                ->toHaveKey('fresh_analyzer_verdict', $case['expected']);
        }

        expect(session_index_record($index, 'direct-child-verdict'))
            ->toHaveKey('fresh_analyzer_verdict_raw', 'yes - final')
            ->toHaveKey('fresh_analyzer_verdict', 'yes');

        expect(session_index_record($index, 'same-line-prose'))
            ->toHaveKey('fresh_analyzer_verdict_raw', 'yes - explanation')
            ->toHaveKey('fresh_analyzer_verdict', 'yes - explanation');

        foreach (['grandchild-verdict', 'prose-verdict'] as $slug) {
            expect(session_index_record($index, $slug))
                ->toHaveKey('fresh_analyzer_verdict_raw', null)
                ->toHaveKey('fresh_analyzer_verdict', 'unknown');
        }
    } finally {
        session_index_remove($workspace);
    }
});

it('treats only exact current no-blocker entries as blocker free', function (): void {
    $workspace = session_index_workspace('exact-current-no-blockers');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $cases = [
            'none-currently' => ['None currently.', false],
            'no-blocker-currently' => ['No blocker currently.', false],
            'no-blocker-currently-without-punctuation' => ['No blocker currently', false],
            'plural-no-blockers-currently' => ['No blockers currently.', true],
            'none-currently-semicolon' => ['None currently; deployment remains blocked.', true],
            'none-currently-dash' => ['None currently - verification is deferred.', true],
            'none-currently-but' => ['None currently, but review remains blocked.', true],
            'no-blocker-currently-qualified' => ['No blocker currently while implementation is deferred.', true],
        ];

        foreach ($cases as $slug => [$blocker, $expected]) {
            session_index_archive(
                sessionsDir: $sessionsDir,
                basename: "2026-07-10-120000-{$slug}",
                loop: session_index_loop(
                    outcome: 'complete',
                    analyzerVerdict: 'not used',
                    blockers: [$blocker],
                ),
            );
        }

        expect(run_session_index($sessionsDir, ['--write'])->getExitCode())->toBe(0);

        $index = session_index_json($sessionsDir);

        foreach ($cases as $slug => [, $expected]) {
            expect(session_index_record($index, $slug))->toHaveKey('blockers_present', $expected);
        }
    } finally {
        session_index_remove($workspace);
    }
});

it('ignores timestamp shaped directories without an archive payload', function (): void {
    $workspace = session_index_workspace('empty-archive-directory');

    try {
        $sessionsDir = "{$workspace}/sessions";

        mkdir("{$sessionsDir}/2026-07-10-120000-empty-directory", recursive: true);
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120100-compact-payload',
            loop: session_index_compact_loop(),
        );
        session_index_archive(
            sessionsDir: $sessionsDir,
            basename: '2026-07-10-120200-legacy-payload',
            loop: session_index_loop(outcome: 'complete'),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);
        $archives = array_column($index['records'], 'archive');

        expect($index)
            ->toHaveKey('record_count', 2)
            ->and($archives)
            ->toBe([
                '2026-07-10-120100-compact-payload',
                '2026-07-10-120200-legacy-payload',
            ])
            ->and(run_session_index($sessionsDir, ['--check'])->getExitCode())
            ->toBe(0);
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

it('rejects receipt candidate_commit values with an embedded trailing newline', function (): void {
    $workspace = session_index_workspace('candidate-trailing-newline');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $hex = '1d9deacb42810f202ac39b45af6e1ca79652564d';

        session_index_archive(
            $sessionsDir,
            '2026-08-05-160000-newline-identity',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-160000-newline-identity", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $hex."\n",
            'copied_entries' => ['loop.md'],
        ]);

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect(session_index_record($index, 'newline-identity')['candidate_commit'])
            ->toBeNull()
            ->and($index)
            ->toHaveKey('record_count', 1)
            ->toHaveKey('unique_candidate_commit_count', 0);
    } finally {
        session_index_remove($workspace);
    }
});

it('stores explicit candidate_commit identity from receipts without guessing history', function (): void {
    $workspace = session_index_workspace('candidate-identity');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $validCommit = '1d9deacb42810f202ac39b45af6e1ca79652564d';
        $otherCommit = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        session_index_archive(
            $sessionsDir,
            '2026-08-05-120000-valid-identity',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-120000-valid-identity", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $validCommit,
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-120100-missing-identity',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-120100-missing-identity", [
            'schema_version' => 2,
            'archive_mode' => 'compact',
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-120200-invalid-identity',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-120200-invalid-identity", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => 'UNKNOWN',
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-120300-uppercase-identity',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-120300-uppercase-identity", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => strtoupper($otherCommit),
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-120400-legacy-no-receipt',
            session_index_loop(outcome: 'complete'),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect(session_index_record($index, 'valid-identity')['candidate_commit'])
            ->toBe($validCommit)
            ->and(session_index_record($index, 'missing-identity')['candidate_commit'])
            ->toBeNull()
            ->and(session_index_record($index, 'invalid-identity')['candidate_commit'])
            ->toBeNull()
            ->and(session_index_record($index, 'uppercase-identity')['candidate_commit'])
            ->toBeNull()
            ->and(session_index_record($index, 'legacy-no-receipt')['candidate_commit'])
            ->toBeNull()
            ->and($index)
            ->toHaveKey('schema_version', 3)
            ->toHaveKey('record_count', 5)
            ->toHaveKey('unique_candidate_commit_count', 1);
    } finally {
        session_index_remove($workspace);
    }
});

it('deduplicates only valid explicit candidate commits and keeps multiple unknowns as raw records', function (): void {
    $workspace = session_index_workspace('unique-candidates');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $shared = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $other = 'cccccccccccccccccccccccccccccccccccccccc';

        session_index_archive(
            $sessionsDir,
            '2026-08-05-130000-shared-a',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-130000-shared-a", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $shared,
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-130100-shared-b',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-130100-shared-b", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $shared,
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-130200-other',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-130200-other", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $other,
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-130300-unknown-a',
            session_index_loop(outcome: 'complete'),
        );
        session_index_archive(
            $sessionsDir,
            '2026-08-05-130400-unknown-b',
            session_index_loop(outcome: 'blocked'),
        );

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);
        $archives = array_column($index['records'], 'archive');
        $commits = array_column($index['records'], 'candidate_commit');

        expect($index)
            ->toHaveKey('record_count', 5)
            ->toHaveKey('unique_candidate_commit_count', 2)
            ->and($archives)
            ->toBe([
                '2026-08-05-130000-shared-a',
                '2026-08-05-130100-shared-b',
                '2026-08-05-130200-other',
                '2026-08-05-130300-unknown-a',
                '2026-08-05-130400-unknown-b',
            ])
            ->and($commits)
            ->toBe([
                $shared,
                $shared,
                $other,
                null,
                null,
            ]);

        $check = run_session_index($sessionsDir, ['--check']);

        expect($check->getExitCode())->toBe(0, $check->getErrorOutput());
    } finally {
        session_index_remove($workspace);
    }
});

it('reports empty and partial orphan archive directories without deleting them', function (): void {
    $workspace = session_index_workspace('orphans');

    try {
        $sessionsDir = "{$workspace}/sessions";

        session_index_archive(
            $sessionsDir,
            '2026-08-05-140000-indexed',
            session_index_loop(outcome: 'complete'),
        );

        $emptyDir = "{$sessionsDir}/2026-08-05-140100-empty-orphan";
        mkdir($emptyDir, recursive: true);

        $partialDir = "{$sessionsDir}/2026-08-05-140200-partial-orphan";
        mkdir($partialDir, recursive: true);
        file_put_contents("{$partialDir}/notes.txt", "partial\n");

        $unsafeDir = "{$sessionsDir}/2026-08-05-140050-unsafe-orphan";
        mkdir($unsafeDir, recursive: true);
        file_put_contents("{$unsafeDir}/real-loop.md", session_index_loop(outcome: 'complete'));
        symlink('real-loop.md', "{$unsafeDir}/loop.md");

        $ignoredDir = "{$sessionsDir}/not-an-archive-name";
        mkdir($ignoredDir, recursive: true);

        $write = run_session_index($sessionsDir, ['--write']);

        expect($write->getExitCode())->toBe(0, $write->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect($index)
            ->toHaveKey('record_count', 1)
            ->toHaveKey('orphan_count', 3)
            ->and($index['orphans'])
            ->toBe([
                [
                    'archive' => '2026-08-05-140050-unsafe-orphan',
                    'reason' => 'unsafe_loop_md',
                ],
                [
                    'archive' => '2026-08-05-140100-empty-orphan',
                    'reason' => 'empty',
                ],
                [
                    'archive' => '2026-08-05-140200-partial-orphan',
                    'reason' => 'missing_loop_md',
                ],
            ])
            ->and(is_dir($emptyDir))
            ->toBeTrue()
            ->and(is_dir($partialDir))
            ->toBeTrue()
            ->and(is_dir($unsafeDir))
            ->toBeTrue()
            ->and(is_link("{$unsafeDir}/loop.md"))
            ->toBeTrue();

        $fresh = run_session_index($sessionsDir, ['--check']);

        expect($fresh->getExitCode())->toBe(0, $fresh->getErrorOutput());

        file_put_contents("{$partialDir}/loop.md", session_index_loop(outcome: 'complete'));

        $stale = run_session_index($sessionsDir, ['--check']);

        expect($stale->getExitCode())
            ->toBe(1)
            ->and($stale->getErrorOutput())
            ->toContain('Session index is stale');
    } finally {
        session_index_remove($workspace);
    }
});

it('documents regeneration backfill only for explicit receipt identity', function (): void {
    $workspace = session_index_workspace('backfill-semantics');

    try {
        $sessionsDir = "{$workspace}/sessions";
        $commit = 'dddddddddddddddddddddddddddddddddddddddd';

        session_index_archive(
            $sessionsDir,
            '2026-08-05-150000-historical-receipt',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-150000-historical-receipt", [
            'schema_version' => 3,
            'archive_mode' => 'compact',
            'candidate_commit' => $commit,
            'copied_entries' => ['loop.md'],
        ]);

        session_index_archive(
            $sessionsDir,
            '2026-08-05-150100-historical-absence',
            session_index_compact_loop(),
        );
        session_index_write_receipt("{$sessionsDir}/2026-08-05-150100-historical-absence", [
            'schema_version' => 2,
            'archive_mode' => 'compact',
            'copied_entries' => ['loop.md'],
        ]);

        $first = run_session_index($sessionsDir, ['--write']);
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput());

        $second = run_session_index($sessionsDir, ['--write']);
        expect($second->getExitCode())->toBe(0, $second->getErrorOutput());

        $index = session_index_json($sessionsDir);

        expect(session_index_record($index, 'historical-receipt')['candidate_commit'])
            ->toBe($commit)
            ->and(session_index_record($index, 'historical-absence')['candidate_commit'])
            ->toBeNull()
            ->and($index['unique_candidate_commit_count'])
            ->toBe(1);

        $help = run_session_index($sessionsDir, ['--help']);

        expect($help->getExitCode())
            ->toBe(0, $help->getErrorOutput())
            ->and($help->getOutput())
            ->toContain('candidate_commit')
            ->toContain('unknown')
            ->toContain('backfill');
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
        '--full',
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

function session_index_write_receipt(string $archiveDir, array $receipt): void
{
    file_put_contents(
        "{$archiveDir}/orbit-session-archive.json",
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
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

function session_index_compact_loop(): string
{
    return <<<'MARKDOWN'
        # Orbit Feature Loop

        ## Goal

        Compact history.

        ## Scope

        - Owned: loop tools

        ## Proof

        - Verification:
          - focused: passed - test
          - broader: passed - quality
          - runtime: not applicable - tooling
        - Review: passed - reviewer 1 - non-observable
        - Blast radius: complete - evidence=docs-lint; result=all affected surfaces aligned
        - Acceptance venue: automated
        - Acceptance: accepted - automated
        - Accepted feature tip: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        - Accepted main tip: bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

        ## Status

        - State: accepted
        - Blocker: none

        ## Feedback

        - Events: `.orbit/feedback.jsonl`
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
            return is_array($record['legacy'] ?? null)
                ? array_merge($record, $record['legacy'])
                : $record;
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
