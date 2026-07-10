<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('round trips immutable feedback and linked promotion events', function (): void {
    require_once repo_path('bin/orbit-feedback-events.php');

    $path = feedback_test_temp_path();

    try {
        orbitFeedbackAppend($path, feedback_test_recorded_event('feedback-1'));
        orbitFeedbackAppend($path, [
            'schema_version' => 1,
            'type' => 'feedback.promoted',
            'id' => 'promotion-1',
            'recorded_at' => '2026-07-10T18:01:00Z',
            'feedback_id' => 'feedback-1',
            'scope' => 'cli.progress',
            'expectation' => 'Long operations continuously communicate liveness.',
            'protection' => [
                'kind' => 'test',
                'reference' => 'bin/quality-check-progress-frame-check',
                'rejected_example' => 'feedback-1',
                'accepted_example' => 'quality-check-progress-monotonic',
            ],
        ]);

        expect(orbitFeedbackRead($path))
            ->toHaveCount(2)
            ->and(fn () => orbitFeedbackAppend($path, feedback_test_recorded_event('feedback-1')))
            ->toThrow(RuntimeException::class, 'duplicate feedback event id')
            ->and(fn () => orbitFeedbackAppend($path, [
                'schema_version' => 1,
                'type' => 'feedback.waived',
                'id' => 'waiver-1',
                'recorded_at' => '2026-07-10T18:02:00Z',
                'feedback_id' => 'missing-feedback',
                'source' => 'user',
                'source_ref' => 'codex://threads/example#waiver',
                'reason' => 'Not relevant.',
                'user_message' => 'Not relevant.',
            ]))
            ->toThrow(RuntimeException::class, 'unknown feedback event');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('refuses to append to an invalid existing feedback stream', function (): void {
    require_once repo_path('bin/orbit-feedback-events.php');

    $path = feedback_test_temp_path();
    $invalid = "{\"schema_version\":1,\"type\":\"unknown\",\"id\":\"bad\",\"recorded_at\":\"2026-07-10T18:00:00Z\"}\n";
    file_put_contents($path, $invalid);

    try {
        expect(fn () => orbitFeedbackAppend($path, feedback_test_recorded_event('feedback-after-invalid')))
            ->toThrow(RuntimeException::class, 'unknown feedback event type')
            ->and((string) file_get_contents($path))
            ->toBe($invalid);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('records non-secret feedback verbatim and redacts secret-bearing feedback before persistence', function (): void {
    $workspace = feedback_test_workspace('record');

    try {
        $normal = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#feedback-1',
                '--surface=cli.progress',
                '--command=orbit deploy app',
            ],
            "The command appears frozen.\n",
        );

        expect($normal->getExitCode())->toBe(0, $normal->getErrorOutput());

        $normalEvent = json_decode(trim($normal->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $token = 'ghp_'.str_repeat('a', 24);
        $secretText = "The error printed {$token}; keep the useful context.";
        $secret = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#feedback-2',
                '--surface=cli.errors',
            ],
            $secretText."\n",
        );

        expect($secret->getExitCode())->toBe(0, $secret->getErrorOutput());

        $secretEvent = json_decode(trim($secret->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $stream = (string) file_get_contents("{$workspace}/.orbit/feedback.jsonl");

        expect($normalEvent)
            ->toHaveKey('raw_text', 'The command appears frozen.')
            ->toHaveKey('candidate_commit', feedback_test_git($workspace, ['rev-parse', 'HEAD']))
            ->and($secretEvent['raw_text'])
            ->toBe('The error printed [REDACTED:github-token]; keep the useful context.')
            ->and($secretEvent['raw_sha256'])
            ->toBe(hash('sha256', $secretText))
            ->and($secretEvent['redactions'])
            ->toBe(['github-token'])
            ->and($stream)
            ->not->toContain($token)->and(feedback_test_tree_contents($workspace))
            ->not->toContain($token)->and("{$workspace}/.orbit/private-sessions")
            ->not->toBeDirectory();
    } finally {
        feedback_test_remove($workspace);
    }
});

it('redacts complete private keys and rejects secrets in durable metadata', function (): void {
    $workspace = feedback_test_workspace('metadata-secrets');

    try {
        $privateKey = implode("\n", [
            '-----BEGIN ENCRYPTED PRIVATE KEY-----',
            str_repeat('A', 64),
            '-----END ENCRYPTED PRIVATE KEY-----',
        ]);
        $record = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#private-key',
                '--surface=cli.errors',
            ],
            "The command printed:\n{$privateKey}\nKeep this context.\n",
        );

        expect($record->getExitCode())->toBe(0, $record->getErrorOutput());

        $event = json_decode($record->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $stream = (string) file_get_contents("{$workspace}/.orbit/feedback.jsonl");
        $oauthToken = 'gho_'.str_repeat('b', 24);
        $metadata = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#metadata',
                '--surface=cli.errors',
                "--command=orbit deploy --token={$oauthToken}",
            ],
            "Useful feedback.\n",
        );

        expect($event['raw_text'])
            ->toBe("The command printed:\n[REDACTED:private-key]\nKeep this context.")
            ->and($stream)
            ->not->toContain(str_repeat('A', 64))->and($metadata->getExitCode())->toBe(
                0,
                $metadata->getErrorOutput(),
            )->and($metadata->getOutput())->toContain('[REDACTED:github-token]')
            ->not->toContain($oauthToken)->and((string) file_get_contents("{$workspace}/.orbit/feedback.jsonl"))
            ->not->toContain($oauthToken);

        $feedbackId = json_decode($metadata->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id'];
        $awsKey = 'AKIA'.str_repeat('C', 16);
        $promotion = feedback_test_run($workspace, [
            'promote',
            "--feedback-id={$feedbackId}",
            '--scope=cli.errors',
            '--kind=test',
            '--reference=bin/error-check',
            "--expectation=Never print {$awsKey}",
            '--rejected-example=secret output',
            '--accepted-example=redacted output',
        ]);

        expect($promotion->getExitCode())
            ->toBe(2)
            ->and($promotion->getErrorOutput())
            ->toContain('secret-shaped metadata')
            ->toContain('expectation')
            ->and((string) file_get_contents("{$workspace}/.orbit/feedback.jsonl"))
            ->not->toContain($awsKey);
    } finally {
        feedback_test_remove($workspace);
    }
});

it('rejects unsafe source references and symlinked feedback paths without external writes', function (): void {
    $workspace = feedback_test_workspace('path-safety');
    $external = feedback_test_workspace('path-safety-external');

    try {
        $token = 'gho_'.str_repeat('d', 24);
        $unsafeReference = feedback_test_run(
            $workspace,
            [
                'record',
                "--session-ref=codex://threads/example?token={$token}",
                '--surface=cli.errors',
            ],
            "Useful feedback.\n",
        );

        expect($unsafeReference->getExitCode())
            ->toBe(2)
            ->and($unsafeReference->getErrorOutput())
            ->toContain('safe Codex or Solo source reference')
            ->and("{$workspace}/.orbit/feedback.jsonl")
            ->not->toBeFile();

        rmdir("{$workspace}/.orbit");
        symlink("{$external}/.orbit", "{$workspace}/.orbit");
        $parentLink = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#parent-link',
                '--surface=cli.errors',
            ],
            "Must not escape.\n",
        );

        expect($parentLink->getExitCode())
            ->toBe(2)
            ->and($parentLink->getErrorOutput())
            ->toContain('feedback directory')
            ->and("{$external}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        feedback_test_remove($workspace);
        feedback_test_remove($external);
    }
});

it('detects a feedback leaf swap before writing through the opened descriptor', function (): void {
    require_once repo_path('bin/orbit-feedback-events.php');

    $directory = sys_get_temp_dir().'/orbit-feedback-swap-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $path = "{$directory}/feedback.jsonl";
    $external = "{$directory}/external.jsonl";
    file_put_contents($path, '');
    file_put_contents($external, "external\n");

    try {
        expect(fn () => orbitFeedbackAppend(
            $path,
            feedback_test_recorded_event('feedback-swap'),
            static function () use ($path, $external): void {
                unlink($path);
                symlink($external, $path);
            },
        ))
            ->toThrow(RuntimeException::class, 'changed before append')
            ->and((string) file_get_contents($external))
            ->toBe("external\n");
    } finally {
        new Process(['rm', '-rf', $directory])->run();
    }
});

it('retrieves only exact and parent-scope feedback from active and archived streams', function (): void {
    $workspace = feedback_test_workspace('relevant');
    $sessions = "{$workspace}/.orbit/sessions";
    mkdir("{$sessions}/2026-07-10-180000-prior", recursive: true);

    try {
        feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#1',
                '--surface=cli',
            ],
            "Parent CLI feedback.\n",
        );
        feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#2',
                '--surface=browser.navigation',
            ],
            "Browser feedback.\n",
        );

        require_once repo_path('bin/orbit-feedback-events.php');
        orbitFeedbackAppend(
            "{$sessions}/2026-07-10-180000-prior/feedback.jsonl",
            feedback_test_recorded_event('archived-progress', surface: 'cli.progress'),
        );
        orbitFeedbackAppend(
            "{$sessions}/2026-07-10-180000-prior/feedback.jsonl",
            [
                'schema_version' => 1,
                'type' => 'feedback.promoted',
                'id' => 'promotion-archived-progress',
                'recorded_at' => '2026-07-10T18:01:00Z',
                'feedback_id' => 'archived-progress',
                'scope' => 'cli.progress',
                'expectation' => 'Progress stays monotonic.',
                'protection' => [
                    'kind' => 'test',
                    'reference' => 'bin/quality-check-progress-frame-check',
                    'rejected_example' => 'running-to-queued',
                    'accepted_example' => 'running-to-passed',
                ],
            ],
        );

        $process = feedback_test_run($workspace, [
            'relevant',
            '--surface=cli.progress',
            '--json',
        ]);
        $events = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(count($events))
            ->toBe(3, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->and(array_column($events, 'type'))
            ->toBe(['feedback.recorded', 'feedback.recorded', 'feedback.promoted'])
            ->and(array_column($events, 'id'))
            ->toBe([$events[0]['id'], 'archived-progress', 'promotion-archived-progress']);
    } finally {
        feedback_test_remove($workspace);
    }
});

it('allows only user-volunteered waivers and requires calibrated promotion examples', function (): void {
    $workspace = feedback_test_workspace('promotion');

    try {
        $record = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#1',
                '--surface=cli.progress',
            ],
            "Progress regressed.\n",
        );
        $feedbackId = json_decode($record->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $agentWaiver = feedback_test_run($workspace, [
            'waive',
            "--feedback-id={$feedbackId}",
            '--source=agent',
            '--reason=Too expensive',
        ]);
        $unprovenUserWaiver = feedback_test_run($workspace, [
            'waive',
            "--feedback-id={$feedbackId}",
            '--source=user',
        ]);
        $missingPair = feedback_test_run($workspace, [
            'promote',
            "--feedback-id={$feedbackId}",
            '--scope=cli.progress',
            '--kind=test',
            '--reference=bin/progress-check',
        ]);

        expect($agentWaiver->getExitCode())
            ->toBe(2)
            ->and($agentWaiver->getErrorOutput())
            ->toContain('source=user')
            ->and($unprovenUserWaiver->getExitCode())
            ->toBe(2)
            ->and($unprovenUserWaiver->getErrorOutput())
            ->toContain('source-ref')
            ->and($missingPair->getExitCode())
            ->toBe(2)
            ->and($missingPair->getErrorOutput())
            ->toContain('rejected and accepted examples');
    } finally {
        feedback_test_remove($workspace);
    }
});

it('binds a user waiver to a safe source and verbatim redacted message', function (): void {
    $workspace = feedback_test_workspace('waiver-provenance');

    try {
        $record = feedback_test_run(
            $workspace,
            [
                'record',
                '--session-ref=codex://threads/example#feedback',
                '--surface=cli.progress',
            ],
            "Progress regressed.\n",
        );
        $feedbackId = json_decode($record->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id'];
        $token = 'gho_'.str_repeat('w', 24);
        $message = "I waive this one case; the transcript contained {$token}.";
        $waiver = feedback_test_run(
            $workspace,
            [
                'waive',
                "--feedback-id={$feedbackId}",
                '--source=user',
                '--source-ref=codex://threads/example#waiver',
            ],
            $message."\n",
        );

        expect($waiver->getExitCode())->toBe(0, $waiver->getErrorOutput());

        $event = json_decode($waiver->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $stream = (string) file_get_contents("{$workspace}/.orbit/feedback.jsonl");

        expect($event)
            ->toMatchArray([
                'type' => 'feedback.waived',
                'feedback_id' => $feedbackId,
                'source' => 'user',
                'source_ref' => 'codex://threads/example#waiver',
                'reason' => 'I waive this one case; the transcript contained [REDACTED:github-token].',
                'user_message' => 'I waive this one case; the transcript contained [REDACTED:github-token].',
            ])
            ->toHaveKey('raw_sha256', hash('sha256', $message))
            ->and($stream)
            ->not->toContain($token);
    } finally {
        feedback_test_remove($workspace);
    }
});

function feedback_test_temp_path(): string
{
    return sys_get_temp_dir().'/orbit-feedback-'.bin2hex(random_bytes(6)).'.jsonl';
}

/**
 * @return array<string, mixed>
 */
function feedback_test_recorded_event(string $id, string $surface = 'cli.progress'): array
{
    return [
        'schema_version' => 1,
        'type' => 'feedback.recorded',
        'id' => $id,
        'recorded_at' => '2026-07-10T18:00:00Z',
        'raw_text' => 'The progress output freezes without explaining what is happening.',
        'session_ref' => 'codex://threads/example#feedback',
        'candidate_commit' => str_repeat('a', 40),
        'surface' => $surface,
        'context' => [],
        'evidence' => [],
    ];
}

function feedback_test_workspace(string $suffix): string
{
    $workspace = sys_get_temp_dir().'/orbit-feedback-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    feedback_test_git($workspace, ['init', '--initial-branch=main']);
    feedback_test_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    feedback_test_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    feedback_test_git($workspace, ['add', 'README.md']);
    feedback_test_git($workspace, ['commit', '-m', 'Initial']);
    mkdir("{$workspace}/.orbit", recursive: true);

    return $workspace;
}

/**
 * @param list<string> $arguments
 */
function feedback_test_run(string $workspace, array $arguments, string $input = ''): Process
{
    $process = new Process([
        repo_path('bin/orbit-feature-feedback'),
        ...$arguments,
        "--cwd={$workspace}",
        "--orbit-dir={$workspace}/.orbit",
    ], $workspace);
    $process->setInput($input);
    $process->run();

    return $process;
}

/**
 * @param list<string> $arguments
 */
function feedback_test_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

function feedback_test_tree_contents(string $workspace): string
{
    $contents = '';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if ($entry->isFile() && ! str_contains($entry->getPathname(), '/.git/')) {
            $contents .= (string) file_get_contents($entry->getPathname());
        }
    }

    return $contents;
}

function feedback_test_remove(string $workspace): void
{
    if (str_contains($workspace, '/orbit-feedback-')) {
        new Process(['rm', '-rf', $workspace])->run();
    }
}
