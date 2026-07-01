<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('noops without explicit solo context or archive directory', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'noop');

    try {
        $archiveDir = "{$temp}/archive";
        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                "--home={$temp}/home",
                "--cwd={$temp}/worktree",
            ],
            repo_path(),
            [
                'SOLO_PROCESS_ID' => false,
                'SOLO_PROJECT_ID' => false,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBeEmpty()
            ->and($archiveDir)
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('archives supported provider sessions and records unsupported providers in the manifest', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'archive');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $archiveDir = "{$temp}/.orbit/sessions/2026-07-01-session-archive/agent-sessions";
    $marker = 'session-archive-fixture-marker';

    try {
        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);

        write_agent_session_archive_fixtures(home: $home, cwd: $cwd, marker: $marker);

        $processesPath = "{$temp}/processes.json";
        write_agent_session_archive_processes(path: $processesPath, cwd: $cwd);

        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                "--processes-json={$processesPath}",
                "--home={$home}",
                "--cwd={$cwd}",
                "--marker={$marker}",
                "--archive-dir={$archiveDir}",
                '--max-start-distance=3600',
            ],
            repo_path(),
            [
                'SOLO_PROCESS_ID' => false,
                'SOLO_PROJECT_ID' => false,
            ],
        );

        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $results = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($results)
            ->toHaveCount(4)
            ->and(provider_status(results: $results, provider: 'codex'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'claude'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'grok'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'antigravity'))
            ->toBe('unsupported');

        $manifest = read_agent_session_archive_json(path: "{$archiveDir}/manifest.json");

        expect($manifest)
            ->toHaveKey('schema_version', 1)
            ->toHaveKey('providers')
            ->and($manifest['providers'])
            ->toMatchArray([
                'codex' => ['ok' => 1],
                'claude' => ['ok' => 1],
                'grok' => ['ok' => 1],
                'antigravity' => ['unsupported' => 1],
            ]);

        assert_provider_archive(archiveDir: $archiveDir, spec: codex_archive_spec(marker: $marker));
        assert_provider_archive(archiveDir: $archiveDir, spec: claude_archive_spec(marker: $marker));
        assert_provider_archive(archiveDir: $archiveDir, spec: grok_archive_spec(marker: $marker));

        $antigravityManifest = read_agent_session_archive_json(
            path: "{$archiveDir}/antigravity/antigravity-reviewer-104/manifest.json",
        );

        expect($antigravityManifest)
            ->toMatchArray([
                'provider' => 'antigravity',
                'status' => 'unsupported',
                'reason' => 'antigravity_session_contract_unsupported',
            ])
            ->and("{$archiveDir}/antigravity/antigravity-reviewer-104/messages.jsonl")
            ->not->toBeFile();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

function make_agent_session_archive_temp_dir(string $suffix): string
{
    $temp = sys_get_temp_dir().'/orbit-agent-session-archive-'.$suffix.'-'.bin2hex(random_bytes(6));

    mkdir($temp, recursive: true);

    return $temp;
}

function remove_agent_session_archive_temp_dir(string $path): void
{
    if ($path === '' || ! str_contains($path, '/orbit-agent-session-archive-')) {
        return;
    }

    new Process(['rm', '-rf', $path])->run();
}

function write_agent_session_archive_processes(string $path, string $cwd): void
{
    file_put_contents($path, json_encode([
        [
            'id' => 101,
            'name' => 'codex-feature',
            'kind' => 'agent',
            'command' => 'codex --model gpt-5',
            'working_dir' => $cwd,
            'started_at' => '2026-07-01T08:00:00Z',
        ],
        [
            'id' => 102,
            'name' => 'claude-implementer',
            'kind' => 'agent',
            'command' => 'claude --model opus',
            'working_dir' => $cwd,
            'pid' => 4321,
            'started_at' => '2026-07-01T08:02:00Z',
        ],
        [
            'id' => 103,
            'name' => 'grok-worker',
            'kind' => 'agent',
            'command' => 'grok',
            'working_dir' => $cwd,
            'started_at' => '2026-07-01T08:03:00Z',
        ],
        [
            'id' => 104,
            'name' => 'antigravity-reviewer',
            'kind' => 'agent',
            'command' => 'antigravity',
            'working_dir' => $cwd,
            'started_at' => '2026-07-01T08:04:00Z',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function write_agent_session_archive_fixtures(string $home, string $cwd, string $marker): void
{
    write_codex_fixture(home: $home, cwd: $cwd, marker: $marker);
    write_claude_fixture(home: $home, marker: $marker);
    write_grok_fixture(home: $home, cwd: $cwd, marker: $marker);
}

function write_codex_fixture(string $home, string $cwd, string $marker): void
{
    $codexDir = "{$home}/.codex/sessions/2026/07/01";
    mkdir($codexDir, recursive: true);
    write_jsonl("{$codexDir}/rollout-2026-07-01T08-00-00-codex-fixture.jsonl", [
        [
            'type' => 'session_meta',
            'payload' => ['id' => 'codex-session-1', 'cwd' => $cwd, 'timestamp' => '2026-07-01T08:00:05Z'],
        ],
        [
            'type' => 'response_item',
            'payload' => [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Codex user '.$marker]],
            ],
        ],
        [
            'type' => 'response_item',
            'payload' => [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Codex assistant response']],
            ],
        ],
        [
            'type' => 'event_msg',
            'payload' => [
                'type' => 'token_count',
                'info' => [
                    'model_context_window' => 200_000,
                    'total_token_usage' => [
                        'input_tokens' => 10,
                        'cached_input_tokens' => 1,
                        'output_tokens' => 5,
                        'reasoning_output_tokens' => 2,
                        'total_tokens' => 17,
                    ],
                ],
            ],
        ],
    ]);
}

function write_claude_fixture(string $home, string $marker): void
{
    $claudeSessionDir = "{$home}/.claude/sessions";
    $claudeProjectDir = "{$home}/.claude/projects/synthetic-project";
    mkdir($claudeSessionDir, recursive: true);
    mkdir($claudeProjectDir, recursive: true);
    file_put_contents("{$claudeSessionDir}/4321.json", json_encode([
        'sessionId' => 'claude-session-1',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    write_jsonl("{$claudeProjectDir}/claude-session-1.jsonl", [
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'Claude user '.$marker]],
        [
            'type' => 'assistant',
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Claude assistant response']],
                'model' => 'claude-opus',
                'usage' => [
                    'input_tokens' => 7,
                    'output_tokens' => 3,
                    'cache_read_input_tokens' => 2,
                    'cache_creation_input_tokens' => 1,
                ],
            ],
        ],
    ]);
}

function write_grok_fixture(string $home, string $cwd, string $marker): void
{
    $grokDir = "{$home}/.grok/sessions/".rawurlencode($cwd).'/grok-session-1';
    mkdir($grokDir, recursive: true);
    write_jsonl("{$grokDir}/chat_history.jsonl", [
        ['role' => 'user', 'content' => 'Grok user '.$marker],
        ['role' => 'assistant', 'content' => 'Grok assistant response'],
    ]);
    write_jsonl("{$grokDir}/events.jsonl", [['event' => 'tool', 'message' => $marker]]);
    write_jsonl("{$grokDir}/updates.jsonl", [['update' => 'done']]);
    file_put_contents("{$grokDir}/signals.json", json_encode([
        'primaryModelId' => 'grok-4',
        'contextTokensUsed' => 321,
        'contextWindowTokens' => 1000,
        'contextWindowUsage' => 32,
        'toolCallCount' => 4,
        'sessionDurationSeconds' => 55,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$grokDir}/summary.json", json_encode([
        'created_at' => '2026-07-01T08:03:05Z',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$grokDir}/prompt_context.json", json_encode([
        'cwd' => $cwd,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$grokDir}/resources_state.json", json_encode(
        ['resources' => []],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ));
    file_put_contents(filename: "{$grokDir}/system_prompt.txt", data: "Synthetic Grok system prompt\n");
    file_put_contents(filename: "{$grokDir}/terminal.log", data: "Synthetic terminal log\n");
}

/**
 * @param list<array<string, mixed>> $rows
 */
function write_jsonl(string $path, array $rows): void
{
    file_put_contents(
        $path,
        collect($rows)->map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES))->implode("\n")."\n",
    );
}

/**
 * @param list<array<string, mixed>> $results
 */
function provider_status(array $results, string $provider): ?string
{
    foreach ($results as $result) {
        if (($result['agent'] ?? null) === $provider) {
            return $result['status'] ?? null;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function read_agent_session_archive_json(string $path): array
{
    expect($path)->toBeFile();

    return json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array{
 *     provider: string,
 *     slug: string,
 *     raw_files: list<string>,
 *     expected_usage: array<string, int>,
 *     expected_messages: list<string>,
 * }
 */
function codex_archive_spec(string $marker): array
{
    return [
        'provider' => 'codex',
        'slug' => 'codex-feature-101',
        'raw_files' => ['rollout-2026-07-01T08-00-00-codex-fixture.jsonl'],
        'expected_usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'reasoning_tokens' => 2, 'total_tokens' => 17],
        'expected_messages' => ['Codex user '.$marker, 'Codex assistant response'],
    ];
}

/**
 * @return array{
 *     provider: string,
 *     slug: string,
 *     raw_files: list<string>,
 *     expected_usage: array<string, int>,
 *     expected_messages: list<string>,
 * }
 */
function claude_archive_spec(string $marker): array
{
    return [
        'provider' => 'claude',
        'slug' => 'claude-implementer-102',
        'raw_files' => ['4321.json', 'claude-session-1.jsonl'],
        'expected_usage' => [
            'input_tokens' => 7,
            'output_tokens' => 3,
            'cache_read_tokens' => 2,
            'cache_write_tokens' => 1,
        ],
        'expected_messages' => ['Claude user '.$marker, 'Claude assistant response'],
    ];
}

/**
 * @return array{
 *     provider: string,
 *     slug: string,
 *     raw_files: list<string>,
 *     expected_usage: array<string, int>,
 *     expected_messages: list<string>,
 * }
 */
function grok_archive_spec(string $marker): array
{
    return [
        'provider' => 'grok',
        'slug' => 'grok-worker-103',
        'raw_files' => [
            'chat_history.jsonl',
            'events.jsonl',
            'prompt_context.json',
            'resources_state.json',
            'signals.json',
            'summary.json',
            'system_prompt.txt',
            'terminal.log',
            'updates.jsonl',
        ],
        'expected_usage' => ['context_tokens_used' => 321, 'tool_call_count' => 4, 'session_duration_seconds' => 55],
        'expected_messages' => ['Grok user '.$marker, 'Grok assistant response'],
    ];
}

/**
 * @param array{
 *     provider: string,
 *     slug: string,
 *     raw_files: list<string>,
 *     expected_usage: array<string, int>,
 *     expected_messages: list<string>,
 * } $spec
 */
function assert_provider_archive(string $archiveDir, array $spec): void
{
    $provider = $spec['provider'];
    $slug = $spec['slug'];
    $providerDir = "{$archiveDir}/{$provider}/{$slug}";
    $manifest = read_agent_session_archive_json(path: "{$providerDir}/manifest.json");
    $usage = read_agent_session_archive_json(path: "{$providerDir}/usage.json");
    $messages = file("{$providerDir}/messages.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($messages === false) {
        $messages = [];
    }

    expect($manifest)
        ->toMatchArray([
            'provider' => $provider,
            'status' => 'ok',
            'slug' => $slug,
        ])
        ->and($usage)
        ->toMatchArray($spec['expected_usage'])
        ->and($messages)
        ->toHaveCount(count($spec['expected_messages']));

    foreach ($spec['expected_messages'] as $index => $expectedMessage) {
        $message = json_decode($messages[$index], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($message['content'] ?? null)->toBe($expectedMessage);
    }

    foreach ($spec['raw_files'] as $rawFile) {
        expect("{$providerDir}/raw/{$rawFile}")->toBeFile();
    }
}
