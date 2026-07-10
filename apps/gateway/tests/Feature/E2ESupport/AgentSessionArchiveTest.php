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
    $archiveDir = "{$temp}/.orbit/sessions/2026-07-01-100305-session-archive/agent-sessions";
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

it('filters multiple target solo processes from fixture exports', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'multi-process');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
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
                '--solo-process-id=101,103',
                "--home={$home}",
                "--cwd={$cwd}",
                "--marker={$marker}",
                '--max-start-distance=3600',
            ],
            repo_path(),
            [
                'SOLO_PROCESS_ID' => false,
                'SOLO_PROJECT_ID' => false,
            ],
        );

        $process->run();

        $results = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($results)
            ->toHaveCount(2)
            ->and(provider_status(results: $results, provider: 'codex'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'grok'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'claude'))
            ->toBeNull();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('continues past unresolvable target solo processes and records them with an explicit status', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'unresolved-target');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $archiveDir = "{$temp}/archive/agent-sessions";
    $marker = 'session-archive-fixture-marker';

    try {
        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);

        $cwd = (string) realpath($cwd);

        write_grok_fixture(home: $home, cwd: $cwd, marker: $marker);

        $soloCliPath = write_agent_session_archive_solo_cli_stub(temp: $temp, cwd: $cwd);

        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                '--solo-process-id=103,987654',
                "--solo-cli={$soloCliPath}",
                "--solo-db={$temp}/missing-solo.db",
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

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('WARNING')
            ->toContain('987654');

        $results = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($results)
            ->toHaveCount(2)
            ->and(provider_status(results: $results, provider: 'grok'))
            ->toBe('ok');

        $unresolvedResult = provider_result(results: $results, provider: 'unknown');

        expect($unresolvedResult)
            ->toMatchArray([
                'status' => 'solo_process_not_found',
                'solo_process_id' => 987654,
            ]);

        $manifest = read_agent_session_archive_json(path: "{$archiveDir}/manifest.json");

        expect($manifest['providers'])
            ->toMatchArray([
                'grok' => ['ok' => 1],
                'unknown' => ['solo_process_not_found' => 1],
            ])
            ->and(collect($manifest['sessions'])->firstWhere('solo_process_id', 987654))
            ->toMatchArray([
                'provider' => 'unknown',
                'status' => 'solo_process_not_found',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('archives active orbit state and provider sessions into one session directory', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'session-wrapper');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $sourceOrbitDir = "{$cwd}/.orbit";
    $archiveDir = "{$temp}/archive-root/2026-07-01-100305-session-wrapper";
    $marker = 'session-archive-fixture-marker';

    try {
        mkdir($home, recursive: true);
        mkdir("{$sourceOrbitDir}/evidence", recursive: true);
        mkdir("{$sourceOrbitDir}/quality-gates", recursive: true);
        mkdir("{$sourceOrbitDir}/sessions/old-session", recursive: true);
        file_put_contents("{$sourceOrbitDir}/loop.md", "- Solo worker: Grok process `103`\n");
        file_put_contents("{$sourceOrbitDir}/evidence/proof.txt", "proof\n");
        file_put_contents("{$sourceOrbitDir}/quality-gates/result.json", "{\"result\":\"passed\"}\n");
        file_put_contents("{$sourceOrbitDir}/sessions/old-session/loop.md", "old\n");

        write_agent_session_archive_fixtures(home: $home, cwd: $cwd, marker: $marker);

        $processesPath = "{$temp}/processes.json";
        write_agent_session_archive_processes(path: $processesPath, cwd: $cwd);

        $process = new Process(
            [
                repo_path('bin/orbit-session-archive'),
                "--source-orbit-dir={$sourceOrbitDir}",
                "--archive-dir={$archiveDir}",
                "--processes-json={$processesPath}",
                "--home={$home}",
                "--cwd={$cwd}",
                "--marker={$marker}",
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

        $summary = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($summary)
            ->toHaveKey('archive_dir', $archiveDir)
            ->and($summary['copied_entries'])
            ->toBe(['evidence', 'loop.md', 'quality-gates'])
            ->and("{$archiveDir}/loop.md")
            ->toBeFile()
            ->and("{$archiveDir}/evidence/proof.txt")
            ->toBeFile()
            ->and("{$archiveDir}/quality-gates/result.json")
            ->toBeFile()
            ->and("{$archiveDir}/sessions/old-session/loop.md")
            ->not->toBeFile();

        $manifest = read_agent_session_archive_json(path: "{$archiveDir}/agent-sessions/manifest.json");

        expect($manifest['providers'])
            ->toMatchArray([
                'codex' => ['ok' => 1],
                'claude' => ['ok' => 1],
                'grok' => ['ok' => 1],
                'antigravity' => ['unsupported' => 1],
            ])
            ->and("{$archiveDir}/orbit-session-archive.json")
            ->toBeFile();

        assert_provider_archive(archiveDir: "{$archiveDir}/agent-sessions", spec: codex_archive_spec(marker: $marker));
        assert_provider_archive(archiveDir: "{$archiveDir}/agent-sessions", spec: claude_archive_spec(marker: $marker));
        assert_provider_archive(archiveDir: "{$archiveDir}/agent-sessions", spec: grok_archive_spec(marker: $marker));
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('creates generated session archives with local timestamp and feature slug names', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'generated-session-wrapper');
    $cwd = "{$temp}/worktree";
    $sourceOrbitDir = "{$cwd}/.orbit";
    $archiveRoot = "{$temp}/archive-root";
    $archiveDir = "{$archiveRoot}/2026-07-01-100305-session-wrapper";

    try {
        mkdir($sourceOrbitDir, recursive: true);
        file_put_contents("{$sourceOrbitDir}/loop.md", "No worker sessions.\n");

        $process = new Process(
            [
                repo_path('bin/orbit-session-archive'),
                "--source-orbit-dir={$sourceOrbitDir}",
                "--archive-root={$archiveRoot}",
                '--timestamp=2026-07-01-100305',
                '--slug=session-wrapper',
                "--cwd={$cwd}",
            ],
            repo_path(),
            [
                'SOLO_PROCESS_ID' => false,
                'SOLO_PROJECT_ID' => false,
            ],
        );

        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $summary = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        $manifest = read_agent_session_archive_json(path: "{$archiveDir}/agent-sessions/manifest.json");

        expect($summary)
            ->toHaveKey('archive_dir', $archiveDir)
            ->and($summary['agent_results'])
            ->toBe([])
            ->and($manifest['providers'])
            ->toBe([])
            ->and("{$archiveDir}/loop.md")
            ->toBeFile();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('documents session archive directory names as local date time and feature slug', function (): void {
    $archiveName = '2026-07-01-100305-session-archive';
    $archivePattern = '/^\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    expect($archiveName)->toMatch($archivePattern);

    collect([
        '2026-07-01-session-archive',
        '2026-07-01T1003-session-archive',
        '20260701100305-session-archive',
        '20260701T080305Z-session-archive',
        '2026-07-01-100305Z-session-archive',
    ])->each(function (string $archiveName) use ($archivePattern): void {
        expect($archiveName)->not->toMatch($archivePattern);
    });

    foreach (session_archive_contract_paths() as $path) {
        $contents = file_get_contents(repo_path($path));
        $normalizedContents = preg_replace(
            pattern: '/\s+/',
            replacement: ' ',
            subject: $contents === false ? '' : $contents,
        );

        if ($normalizedContents === null) {
            $normalizedContents = '';
        }

        if ($path === 'HARNESS.md') {
            expect($normalizedContents)
                ->toContain('YYYY-MM-DD-HHMMSS-<feature-slug>')
                ->toContain(
                    'Do not use compact timestamps, `T` separators, `Z`, or UTC offsets in archive directory names.',
                );

            continue;
        }

        $statesNamingContract = str_contains($normalizedContents, 'YYYY-MM-DD-HHMMSS-<feature-slug>')
        && str_contains(
            $normalizedContents,
            'Do not use compact timestamps, `T` separators, `Z`, or UTC offsets in archive directory names.',
        );
        $pointsAtArchiveTool = str_contains(
            $normalizedContents,
            '`bin/orbit-session-archive` generates and enforces the archive directory name',
        );

        expect($statesNamingContract || $pointsAtArchiveTool)->toBeTrue(
            "{$path} must restate the archive naming contract or point at `bin/orbit-session-archive` as the naming authority.",
        );
    }
});

it('recovers provider sessions when start times are stale and claude pid mapping is unavailable', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'stale-start');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $marker = 'session-archive-fixture-marker';

    try {
        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);

        $cwd = (string) realpath($cwd);

        write_codex_fixture(home: $home, cwd: $cwd, marker: $marker);
        write_grok_fixture(home: $home, cwd: $cwd, marker: $marker);
        write_claude_project_dir_fixture(home: $home, cwd: $cwd, marker: $marker);

        $processesPath = "{$temp}/processes.json";
        file_put_contents($processesPath, json_encode([
            [
                'id' => 201,
                'name' => 'codex-late',
                'kind' => 'agent',
                'command' => 'codex --model gpt-5',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T02:00:00Z',
            ],
            [
                'id' => 202,
                'name' => 'claude-late',
                'kind' => 'agent',
                'command' => 'claude --model opus',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T02:00:00Z',
            ],
            [
                'id' => 203,
                'name' => 'grok-late',
                'kind' => 'agent',
                'command' => 'grok',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T02:00:00Z',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                "--processes-json={$processesPath}",
                "--home={$home}",
                "--cwd={$cwd}",
                "--marker={$marker}",
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
            ->toHaveCount(3)
            ->and(provider_status(results: $results, provider: 'codex'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'grok'))
            ->toBe('ok')
            ->and(provider_status(results: $results, provider: 'claude'))
            ->toBe('ok');

        $claudeResult = provider_result(results: $results, provider: 'claude');
        $encodedProjectDir = "{$home}/.claude/projects/".preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);

        expect($claudeResult)
            ->toMatchArray([
                'artifact' => "{$encodedProjectDir}/claude-session-2.jsonl",
                'input_tokens' => 7,
                'output_tokens' => 3,
                'cache_read_tokens' => 2,
                'cache_write_tokens' => 1,
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('names the exact searched provider paths when session capture fails', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'missing-paths');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";

    try {
        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);

        $cwd = (string) realpath($cwd);

        $processesPath = "{$temp}/processes.json";
        file_put_contents($processesPath, json_encode([
            [
                'id' => 301,
                'name' => 'codex-missing',
                'kind' => 'agent',
                'command' => 'codex',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T08:00:00Z',
            ],
            [
                'id' => 302,
                'name' => 'claude-missing',
                'kind' => 'agent',
                'command' => 'claude',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T08:00:00Z',
            ],
            [
                'id' => 303,
                'name' => 'grok-missing',
                'kind' => 'agent',
                'command' => 'grok',
                'working_dir' => $cwd,
                'started_at' => '2026-07-01T08:00:00Z',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                "--processes-json={$processesPath}",
                "--home={$home}",
                "--cwd={$cwd}",
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
        $codexResult = provider_result(results: $results, provider: 'codex');
        $claudeResult = provider_result(results: $results, provider: 'claude');
        $grokResult = provider_result(results: $results, provider: 'grok');
        $encodedProjectDir = "{$home}/.claude/projects/".preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);

        expect($codexResult)
            ->toMatchArray(['status' => 'missing', 'reason' => 'codex_token_count_not_found'])
            ->and(implode("\n", $codexResult['checked']))
            ->toContain("{$home}/.codex/sessions")
            ->toContain('token_count')
            ->and($claudeResult)
            ->toMatchArray(['status' => 'missing', 'reason' => 'claude_session_not_found'])
            ->and(implode("\n", $claudeResult['checked']))
            ->toContain($encodedProjectDir)
            ->toContain("{$home}/.claude/sessions")
            ->and($grokResult)
            ->toMatchArray(['status' => 'missing', 'reason' => 'grok_signals_not_found'])
            ->and(implode("\n", $grokResult['checked']))
            ->toContain("{$home}/.grok/sessions/".rawurlencode($cwd));
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

/**
 * @return list<string>
 */
function session_archive_contract_paths(): array
{
    return [
        'HARNESS.md',
        'LOOP.md.example',
        'harness-signals/README.md',
        '.agents/skills/handling-feature-requests/SKILL.md',
        '.agents/skills/implementing-features/SKILL.md',
    ];
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

/**
 * Writes an executable Solo CLI stub that resolves only `processes get 103 --json`
 * so tests can mix one resolvable and one unresolvable target process id.
 */
function write_agent_session_archive_solo_cli_stub(string $temp, string $cwd): string
{
    $soloCliPath = "{$temp}/solo-cli-stub";
    $processJson = json_encode([
        'process' => [
            'id' => 103,
            'name' => 'grok-worker',
            'kind' => 'agent',
            'command' => 'grok',
            'working_dir' => $cwd,
            'started_at' => '2026-07-01T08:03:00Z',
        ],
    ], JSON_UNESCAPED_SLASHES);

    file_put_contents($soloCliPath, <<<BASH
        #!/bin/sh
        if [ "\$3" = "processes" ] && [ "\$4" = "get" ] && [ "\$5" = "103" ]; then
            cat <<'JSON'
        {$processJson}
        JSON
            exit 0
        fi
        exit 1

        BASH);
    chmod($soloCliPath, 0o755);

    return $soloCliPath;
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
            'payload' => [
                'id' => 'codex-session-1',
                'cwd' => $cwd,
                'timestamp' => '2026-07-01T08:00:05Z',
                'base_instructions' => [
                    'text' => 'Codex base instructions '.$marker,
                ],
            ],
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

function write_claude_project_dir_fixture(string $home, string $cwd, string $marker): void
{
    $encodedProjectDir = "{$home}/.claude/projects/".preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);
    mkdir("{$encodedProjectDir}/decoy.jsonl", recursive: true);
    write_jsonl("{$encodedProjectDir}/claude-session-2.jsonl", [
        ['type' => 'user', 'cwd' => $cwd, 'message' => ['role' => 'user', 'content' => 'Claude user '.$marker]],
        [
            'type' => 'assistant',
            'cwd' => $cwd,
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
        ['type' => 'system', 'content' => 'Synthetic Grok system prompt'],
        ['type' => 'user', 'content' => [['type' => 'text', 'text' => 'Grok user '.$marker]]],
        ['type' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Grok assistant response']]],
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
 * @return array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * }
 */
function make_incarnation_floor_capture_fixture(string $suffix, int $soloProcessId, string $command = 'codex'): array
{
    $temp = make_agent_session_archive_temp_dir(suffix: "incarnation-floor-{$suffix}");
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $orbitDir = "{$temp}/.orbit";
    $codexDir = "{$home}/.codex/sessions/2026/07/09";

    mkdir($cwd, recursive: true);
    mkdir($orbitDir, recursive: true);
    mkdir($codexDir, recursive: true);

    $soloDb = "{$temp}/solo.db";
    $db = new PDO('sqlite:'.$soloDb);
    $db->exec(
        'CREATE TABLE processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT, started_at TEXT)',
    );
    $statement = $db->prepare(
        'INSERT INTO processes (id, project_id, name, command, working_dir, kind, started_at) VALUES (?, 4, ?, ?, ?, ?, ?)',
    );
    $statement->execute([
        $soloProcessId,
        "incarnation-floor-{$suffix}",
        $command,
        $cwd,
        'agent',
        '2026-07-09T09:00:00Z',
    ]);

    return [
        'temp' => $temp,
        'home' => $home,
        'cwd' => $cwd,
        'orbit_dir' => $orbitDir,
        'solo_db' => $soloDb,
        'codex_dir' => $codexDir,
    ];
}

/**
 * @param array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * } $fixture
 * @param list<array<string, mixed>> $activityRows
 */
function write_incarnation_floor_rollout(
    array $fixture,
    string $rolloutId,
    int $soloProcessId,
    array $activityRows,
    string $sessionMetaTimestamp = '2026-07-09T09:00:00Z',
): void {
    write_jsonl("{$fixture['codex_dir']}/rollout-{$rolloutId}.jsonl", [
        [
            'timestamp' => $sessionMetaTimestamp,
            'type' => 'session_meta',
            'payload' => [
                'id' => $rolloutId,
                'cwd' => $fixture['cwd'],
                'timestamp' => $sessionMetaTimestamp,
                'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
            ],
        ],
        ...$activityRows,
    ]);
}

/**
 * @param array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * } $fixture
 */
function run_incarnation_floor_capture(
    array $fixture,
    int $soloProcessId,
    string $slug,
    ?string $incarnationStartedAt = null,
): Process {
    $command = [
        repo_path('bin/orbit-agent-session-capture'),
        (string) $soloProcessId,
        "--home={$fixture['home']}",
        "--cwd={$fixture['cwd']}",
        "--solo-db={$fixture['solo_db']}",
        "--orbit-dir={$fixture['orbit_dir']}",
        "--slug={$slug}",
    ];

    if ($incarnationStartedAt !== null) {
        $command[] = "--incarnation-started-at={$incarnationStartedAt}";
    }

    $process = new Process($command, repo_path());
    $process->run();

    return $process;
}

/**
 * @return array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * }
 */
function make_provider_capture_fixture(string $provider, string $suffix, int $soloProcessId): array
{
    return make_incarnation_floor_capture_fixture(
        suffix: "r2-{$provider}-{$suffix}",
        soloProcessId: $soloProcessId,
        command: $provider,
    );
}

/**
 * @param array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * } $fixture
 */
function run_provider_capture(array $fixture, int $soloProcessId, string $slug): Process
{
    return run_incarnation_floor_capture(
        fixture: $fixture,
        soloProcessId: $soloProcessId,
        slug: $slug,
    );
}

/**
 * @param array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * } $fixture
 * @param array{
 *     marker_solo_process_id: int,
 *     primary_solo_process_id: int|null,
 *     structural_cwd?: string,
 *     context_cwd?: string|null,
 *     context_key?: string,
 * } $candidateConfig
 */
function write_provider_capture_candidate(
    array $fixture,
    string $provider,
    string $candidate,
    string $candidateCwd,
    array $candidateConfig,
): string {
    $markerSoloProcessId = $candidateConfig['marker_solo_process_id'];
    $primarySoloProcessId = $candidateConfig['primary_solo_process_id'];
    $structuralCwd = $candidateConfig['structural_cwd'] ?? $candidateCwd;
    $includeContextCwd =
        ! array_key_exists('context_cwd', $candidateConfig) || $candidateConfig['context_cwd'] !== null;
    $contextCwd = $candidateConfig['context_cwd'] ?? $candidateCwd;
    $contextKey = $candidateConfig['context_key'] ?? 'working_directory';
    $primaryPrompt = $primarySoloProcessId === null
        ? 'Legacy provider prompt without primary identity.'
        : "[SOLO ORCHESTRATION CONTEXT]\nSolo process ID: {$primarySoloProcessId}\n[END SOLO ORCHESTRATION CONTEXT]";

    if ($provider === 'claude') {
        $projectDirectory = provider_fixture_claude_project_directory($fixture['home'], $structuralCwd);
        $path = "{$projectDirectory}/{$candidate}.jsonl";

        if (! is_dir($projectDirectory)) {
            mkdir($projectDirectory, recursive: true);
        }

        $cwdContext = $includeContextCwd ? ['cwd' => $contextCwd] : [];
        write_jsonl($path, [
            [
                'type' => 'user',
                ...$cwdContext,
                'message' => ['role' => 'user', 'content' => $primaryPrompt],
            ],
            [
                'type' => 'user',
                ...$cwdContext,
                'message' => [
                    'role' => 'user',
                    'content' => "Implement lane for Solo process ID: {$markerSoloProcessId}",
                ],
            ],
            [
                'type' => 'assistant',
                ...$cwdContext,
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Claude response']],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ],
            ],
        ]);

        return $path;
    }

    $sessionRoot = provider_fixture_grok_session_root($fixture['home'], $structuralCwd);
    $sessionDir = "{$sessionRoot}/{$candidate}";
    mkdir($sessionDir, recursive: true);
    write_jsonl("{$sessionDir}/chat_history.jsonl", [
        ['type' => 'user', 'content' => $primaryPrompt],
        ['type' => 'user', 'content' => "Implement lane for Solo process ID: {$markerSoloProcessId}"],
        ['type' => 'assistant', 'content' => 'Grok response'],
    ]);
    $promptContext = $includeContextCwd ? [$contextKey => $contextCwd] : ['model' => 'grok-4'];
    file_put_contents(
        "{$sessionDir}/prompt_context.json",
        json_encode($promptContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    file_put_contents("{$sessionDir}/signals.json", json_encode(
        ['primaryModelId' => 'grok-4'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));

    return $sessionDir;
}

/**
 * @param array{
 *     temp: string,
 *     home: string,
 *     cwd: string,
 *     orbit_dir: string,
 *     solo_db: string,
 *     codex_dir: string,
 * } $fixture
 */
function write_provider_required_artifact_symlink(
    array $fixture,
    string $provider,
    string $source,
    ?string $structuralCwd = null,
): string {
    $structuralCwd ??= $fixture['cwd'];

    if ($provider === 'claude') {
        $projectDirectory = provider_fixture_claude_project_directory($fixture['home'], $structuralCwd);
        $path = "{$projectDirectory}/symlink-session.jsonl";
        mkdir(dirname($path), recursive: true);
        symlink($source, $path);

        return $path;
    }

    $sessionDir = provider_fixture_grok_session_root($fixture['home'], $structuralCwd).'/symlink';
    mkdir($sessionDir, recursive: true);
    file_put_contents("{$sessionDir}/prompt_context.json", json_encode(
        ['working_directory' => $structuralCwd],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
    $path = "{$sessionDir}/chat_history.jsonl";
    symlink($source, $path);

    return $path;
}

function provider_fixture_normalized_cwd(string $cwd): string
{
    return realpath($cwd) ?: $cwd;
}

function provider_fixture_claude_project_directory(string $home, string $cwd): string
{
    $encodedCwd = preg_replace('/[^a-zA-Z0-9]/', '-', provider_fixture_normalized_cwd($cwd));

    return "{$home}/.claude/projects/{$encodedCwd}";
}

function provider_fixture_grok_session_root(string $home, string $cwd): string
{
    return "{$home}/.grok/sessions/".rawurlencode(provider_fixture_normalized_cwd($cwd));
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
 * @param list<array<string, mixed>> $results
 *
 * @return array<string, mixed>
 */
function provider_result(array $results, string $provider): array
{
    foreach ($results as $result) {
        if (($result['agent'] ?? null) === $provider) {
            return $result;
        }
    }

    return [];
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
        'expected_messages' => ['Codex base instructions '.$marker, 'Codex user '.$marker, 'Codex assistant response'],
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
        'expected_messages' => ['Synthetic Grok system prompt', 'Grok user '.$marker, 'Grok assistant response'],
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

it('lane-close capture stages exactly one session via exact "Solo process ID: <id>" marker join', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-exact');

    try {
        $home = "{$temp}/home";
        $cwd = "{$temp}/worktree";
        $orbitDir = "{$temp}/.orbit";
        $stagingDir = "{$orbitDir}/agent-sessions";
        $soloProcessId = 424242;

        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);

        // Fixture: codex rollout containing the exact marker in base_instructions (first prompt)
        $codexDir = "{$home}/.codex/sessions/2026/07/07";
        mkdir($codexDir, recursive: true);
        write_jsonl("{$codexDir}/rollout-2026-07-07T10-00-00-exact.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'capture-exact-codex',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-07T10:00:05Z',
                    'base_instructions' => [
                        'text' => "You are implementing...\nSolo process ID: {$soloProcessId}\n",
                    ],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Environment context without the Solo marker.']],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Implement feature '.$soloProcessId]],
                ],
            ],
            [
                'type' => 'event_msg',
                'payload' => [
                    'type' => 'token_count',
                    'info' => [
                        'model_context_window' => 128000,
                        'total_token_usage' => [
                            'input_tokens' => 42,
                            'output_tokens' => 7,
                            'total_tokens' => 49,
                        ],
                    ],
                ],
            ],
        ]);

        // A decoy rollout without the marker (should not be picked)
        write_jsonl("{$codexDir}/rollout-2026-07-07T09-00-00-decoy.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'decoy',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-07T09:00:00Z',
                    'base_instructions' => ['text' => 'No marker here'],
                ],
            ],
        ]);

        // Simulate solo.db with the process row (for resolution of provider/cwd)
        $soloDb = "{$temp}/solo.db";
        // Minimal schema + row for test (capture command must query it)
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT)',
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS spawned_processes (id INTEGER PRIMARY KEY, pid INTEGER, process_name TEXT, command TEXT, project_path TEXT, spawned_at TEXT, owner_pid INTEGER)',
        );
        $stmt = $db->prepare(
            'INSERT INTO processes (id, project_id, name, command, working_dir, kind) VALUES (?, 4, ?, ?, ?, ?)',
        );
        $stmt->execute([$soloProcessId, 'lane-close-capture-worker', 'php ... codex', $cwd, 'agent']);
        // Also a spawned_processes row to simulate alive row
        $db->exec(
            "INSERT INTO spawned_processes (pid, process_name, command, project_path, spawned_at, owner_pid) VALUES (99999, 'lane-close-capture-worker', 'codex', '{$cwd}', datetime('now'), 800)",
        );

        $process = new Process(
            [
                repo_path('bin/orbit-agent-session-capture'),
                (string) $soloProcessId,
                "--home={$home}",
                "--cwd={$cwd}",
                "--solo-db={$soloDb}",
                "--orbit-dir={$orbitDir}",
                "--slug=capture-exact-{$soloProcessId}",
            ],
            repo_path(),
        );

        $process->run();

        // This will be RED until the capture bin exists and implements exact marker + staging
        expect($process->getExitCode())->toBe(0, 'capture exit: '.$process->getErrorOutput().$process->getOutput());

        $manifestPath = "{$stagingDir}/codex/capture-exact-{$soloProcessId}/manifest.json";
        expect($manifestPath)->toBeFile('staged manifest missing after exact capture');

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['status'] ?? null)
            ->toBe('ok')
            ->and($manifest['solo_process_id'] ?? null)
            ->toBe($soloProcessId)
            ->and($manifest['marker_match'] ?? null)
            ->toBe('exact');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('lane-close capture joins Codex sessions when Solo marker is in the first user prompt', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-codex-first-user');

    try {
        $home = "{$temp}/home";
        $cwd = "{$temp}/worktree";
        $orbitDir = "{$temp}/.orbit";
        $soloProcessId = 434343;

        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);

        $codexDir = "{$home}/.codex/sessions/2026/07/07";
        mkdir($codexDir, recursive: true);

        write_jsonl("{$codexDir}/rollout-child-first-user-{$soloProcessId}.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'child-first-user',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-07T10:10:05Z',
                    'base_instructions' => ['text' => 'Codex system prompt without the Solo marker.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => "Solo process ID: {$soloProcessId}\nImplement the feature.",
                    ]],
                ],
            ],
        ]);

        write_jsonl("{$codexDir}/rollout-parent-log.jsonl", [[
            'type' => 'response_item',
            'payload' => [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => "spawn_agent logged Solo process ID: {$soloProcessId}",
                ]],
            ],
        ]]);

        $soloDb = "{$temp}/solo.db";
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT)',
        );
        $stmt = $db->prepare(
            'INSERT INTO processes (id, project_id, name, command, working_dir, kind) VALUES (?, 4, ?, ?, ?, ?)',
        );
        $stmt->execute([$soloProcessId, 'codex-first-user-worker', 'codex', $cwd, 'agent']);

        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            (string) $soloProcessId,
            "--home={$home}",
            "--cwd={$cwd}",
            "--solo-db={$soloDb}",
            "--orbit-dir={$orbitDir}",
            "--slug=codex-first-user-{$soloProcessId}",
        ], repo_path());

        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $manifestPath = "{$orbitDir}/agent-sessions/codex/codex-first-user-{$soloProcessId}/manifest.json";
        $manifest = json_decode((string) file_get_contents($manifestPath), true, JSON_THROW_ON_ERROR);

        expect($manifest['status'])
            ->toBe('ok')
            ->and($manifest['solo_process_id'])
            ->toBe($soloProcessId)
            ->and($manifest['artifact'])
            ->toContain('rollout-child-first-user');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('lane-close capture fails loudly with diagnostics on duplicate markers for same solo process id', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-dupe');

    try {
        $home = "{$temp}/home";
        $cwd = "{$temp}/worktree";
        $orbitDir = "{$temp}/.orbit";
        $soloProcessId = 555555;

        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);

        // Two rollouts both containing the exact marker -> ambiguity
        $codexDir = "{$home}/.codex/sessions/2026/07/07";
        mkdir($codexDir, recursive: true);
        foreach ([
            'a' => '2026-07-07T11:00:01Z',
            'b' => '2026-07-07T11:25:00Z',
        ] as $suffix => $timestamp) {
            write_jsonl("{$codexDir}/rollout-2026-07-07-dupe-{$suffix}.jsonl", [[
                'type' => 'session_meta',
                'payload' => [
                    'id' => "dupe-{$suffix}",
                    'cwd' => $cwd,
                    'timestamp' => $timestamp,
                    'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
                ],
            ]]);
        }

        $soloDb = "{$temp}/solo.db";
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT, started_at TEXT)',
        );
        $stmt = $db->prepare(
            'INSERT INTO processes (id, project_id, name, command, working_dir, kind, started_at) VALUES (?, 4, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $soloProcessId,
            'dupe-worker',
            'codex',
            $cwd,
            'agent',
            '2026-07-07T11:00:00Z',
        ]);
        $db = null; // close to flush

        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            (string) $soloProcessId,
            "--home={$home}",
            "--cwd={$cwd}",
            "--solo-db={$soloDb}",
            "--orbit-dir={$orbitDir}",
        ], repo_path());

        $process->run();

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('ambiguous_duplicate_markers')
            ->toContain((string) $soloProcessId);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('lane-close capture disambiguates an inherited marker by primary Solo identity', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-inherited-marker');

    try {
        $home = "{$temp}/home";
        $cwd = "{$temp}/worktree";
        $orbitDir = "{$temp}/.orbit";
        $soloProcessId = 919191;

        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);

        $codexDir = "{$home}/.codex/sessions/2026/07/09";
        mkdir($codexDir, recursive: true);

        write_jsonl("{$codexDir}/rollout-child-{$soloProcessId}.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'target-child',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-09T10:00:05Z',
                    'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => "Solo process ID: {$soloProcessId}\nImplement the feature.",
                    ]],
                ],
            ],
        ]);

        write_jsonl("{$codexDir}/rollout-foreign-inherited-{$soloProcessId}.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'foreign-parent',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-09T10:00:06Z',
                    'base_instructions' => ['text' => 'Parent orchestrator instructions.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => 'Coordinate the child handoff.',
                    ]],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => "Solo process ID: {$soloProcessId}\nInherited child handoff.",
                    ]],
                ],
            ],
        ]);

        $soloDb = "{$temp}/solo.db";
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT, started_at TEXT)',
        );
        $stmt = $db->prepare(
            'INSERT INTO processes (id, project_id, name, command, working_dir, kind, started_at) VALUES (?, 4, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $soloProcessId,
            'target-child-worker',
            'codex',
            $cwd,
            'agent',
            '2026-07-09 10:00:00',
        ]);

        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            (string) $soloProcessId,
            "--home={$home}",
            "--cwd={$cwd}",
            "--solo-db={$soloDb}",
            "--orbit-dir={$orbitDir}",
            "--slug=inherited-marker-{$soloProcessId}",
        ], repo_path());

        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $manifestPath = "{$orbitDir}/agent-sessions/codex/inherited-marker-{$soloProcessId}/manifest.json";
        $manifest = json_decode((string) file_get_contents($manifestPath), true, JSON_THROW_ON_ERROR);

        expect($manifest['status'])
            ->toBe('ok')
            ->and($manifest['artifact'])
            ->toContain("rollout-child-{$soloProcessId}")
            ->and($manifest['timestamp_corroboration'] ?? null)
            ->toBe('corroborated')
            ->and($manifest['disambiguation_basis'] ?? null)
            ->not->toBeEmpty();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('stage 2 exact identity rejects numeric-prefix marker collisions', function (string $provider): void {
    $soloProcessId = 12;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: "numeric-prefix-{$provider}",
        soloProcessId: $soloProcessId,
        command: $provider,
    );
    $slug = "numeric-prefix-{$provider}";

    try {
        if ($provider === 'codex') {
            write_incarnation_floor_rollout(
                fixture: $fixture,
                rolloutId: 'numeric-prefix',
                soloProcessId: 123,
                activityRows: [],
            );
        } elseif ($provider === 'claude') {
            write_claude_project_dir_fixture(
                home: $fixture['home'],
                cwd: $fixture['cwd'],
                marker: 'Solo process ID: 123',
            );
        } else {
            write_grok_fixture(
                home: $fixture['home'],
                cwd: $fixture['cwd'],
                marker: 'Solo process ID: 123',
            );
        }

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('exact_marker_not_found');

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'exact_marker_not_found',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['codex', 'claude', 'grok']);

it('stage 2 exact identity rejects a wrong-cwd Codex singleton', function (): void {
    $soloProcessId = 979_810;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'wrong-cwd-singleton', soloProcessId: $soloProcessId);
    $slug = 'wrong-cwd-singleton';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-wrong-cwd.jsonl", [[
            'type' => 'session_meta',
            'payload' => [
                'id' => 'wrong-cwd',
                'cwd' => "{$fixture['temp']}/foreign-worktree",
                'timestamp' => '2026-07-09T10:00:00Z',
                'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
            ],
        ]]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('no_owned_marker_transcript');

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'no_owned_marker_transcript',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity rejects a foreign-primary Codex singleton with a later target marker', function (): void {
    $soloProcessId = 979_811;
    $foreignSoloProcessId = 979_812;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'foreign-primary-singleton',
        soloProcessId: $soloProcessId,
    );
    $slug = 'foreign-primary-singleton';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-foreign-primary.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'foreign-primary',
                    'cwd' => $fixture['cwd'],
                    'timestamp' => '2026-07-09T10:00:00Z',
                    'base_instructions' => ['text' => "Solo process ID: {$foreignSoloProcessId}"],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Continue the foreign lane.']],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => "Solo process ID: {$soloProcessId}"]],
                ],
            ],
        ]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('no_owned_marker_transcript');

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'no_owned_marker_transcript',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity prefers a full Codex owner over a partial owner and records the basis', function (): void {
    $soloProcessId = 979_813;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'full-over-partial', soloProcessId: $soloProcessId);
    $slug = 'full-over-partial';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-partial-newer.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'partial-newer',
                    'cwd' => $fixture['cwd'],
                    'timestamp' => '2030-01-01T00:00:00Z',
                    'base_instructions' => ['text' => 'Legacy lane without primary identity.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Legacy first message.']],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => "Solo process ID: {$soloProcessId}"]],
                ],
            ],
        ]);
        write_jsonl("{$fixture['codex_dir']}/rollout-full-older.jsonl", [[
            'type' => 'session_meta',
            'payload' => [
                'id' => 'full-older',
                'cwd' => $fixture['cwd'],
                'timestamp' => '2020-01-01T00:00:00Z',
                'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
            ],
        ]]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest['artifact'])
            ->toContain('rollout-full-older')
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toContain('ownership=full');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity accepts a sole exact-cwd legacy Codex candidate as visibly partial ownership', function (): void {
    $soloProcessId = 979_814;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'legacy-partial', soloProcessId: $soloProcessId);
    $slug = 'legacy-partial';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-legacy-partial.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'legacy-partial',
                    'cwd' => $fixture['cwd'],
                    'timestamp' => '2026-07-09T10:00:00Z',
                    'base_instructions' => ['text' => 'Legacy lane without primary identity.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Legacy first message.']],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => "Solo process ID: {$soloProcessId}"]],
                ],
            ],
        ]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())->toBeGreaterThan(0);

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'partial',
                'reason' => 'missing_primary_identity',
            ])
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toBeString()
            ->toContain('ownership=partial(no_primary_identity)');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity keeps multiple partial-only Codex candidates loudly ambiguous', function (): void {
    $soloProcessId = 979_815;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'multiple-legacy-partials',
        soloProcessId: $soloProcessId,
    );
    $slug = 'multiple-legacy-partials';

    try {
        foreach (['first', 'second'] as $candidate) {
            write_jsonl("{$fixture['codex_dir']}/rollout-legacy-partial-{$candidate}.jsonl", [
                [
                    'type' => 'session_meta',
                    'payload' => [
                        'id' => "legacy-partial-{$candidate}",
                        'cwd' => $fixture['cwd'],
                        'timestamp' => '2026-07-09T10:00:00Z',
                        'base_instructions' => ['text' => 'Legacy lane without primary identity.'],
                    ],
                ],
                [
                    'type' => 'response_item',
                    'payload' => [
                        'type' => 'message',
                        'role' => 'user',
                        'content' => [['type' => 'input_text', 'text' => 'Legacy first message.']],
                    ],
                ],
                [
                    'type' => 'response_item',
                    'payload' => [
                        'type' => 'message',
                        'role' => 'user',
                        'content' => [['type' => 'input_text', 'text' => "Solo process ID: {$soloProcessId}"]],
                    ],
                ],
            ]);
        }

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('ambiguous_duplicate_markers');

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'ambiguous',
                'reason' => 'ambiguous_duplicate_markers',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity primary identity treats a mention-only first user candidate as visibly partial', function (): void {
    $soloProcessId = 979_816;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'mention-only-partial', soloProcessId: $soloProcessId);
    $slug = 'mention-only-partial';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-mention-only-partial.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'mention-only-partial',
                    'cwd' => $fixture['cwd'],
                    'timestamp' => '2026-07-09T10:00:00Z',
                    'base_instructions' => ['text' => 'Coordinate the child lane.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => "coordinate spawned child Solo process ID: {$soloProcessId}",
                    ]],
                ],
            ],
        ]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())->toBeGreaterThan(0);

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'partial',
                'reason' => 'missing_primary_identity',
            ])
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toBeString()
            ->toContain('ownership=partial(no_primary_identity)');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity primary identity prefers a standalone full owner over a mention-only candidate', function (): void {
    $soloProcessId = 979_817;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'standalone-over-mention', soloProcessId: $soloProcessId);
    $slug = 'standalone-over-mention';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-mention-only.jsonl", [
            [
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'mention-only',
                    'cwd' => $fixture['cwd'],
                    'timestamp' => '2030-01-01T00:00:00Z',
                    'base_instructions' => ['text' => 'Coordinate the child lane.'],
                ],
            ],
            [
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => "coordinate spawned child Solo process ID: {$soloProcessId}",
                    ]],
                ],
            ],
        ]);
        write_jsonl("{$fixture['codex_dir']}/rollout-standalone-full.jsonl", [[
            'type' => 'session_meta',
            'payload' => [
                'id' => 'standalone-full',
                'cwd' => $fixture['cwd'],
                'timestamp' => '2020-01-01T00:00:00Z',
                'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
            ],
        ]]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest['artifact'])
            ->toContain('rollout-standalone-full')
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toBeString()
            ->toContain('ownership=full');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 2 exact identity primary identity treats multiple standalone identity markers as partial', function (): void {
    $soloProcessId = 979_818;
    $otherSoloProcessId = 979_819;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'multiple-standalone-identities',
        soloProcessId: $soloProcessId,
    );
    $slug = 'multiple-standalone-identities';

    try {
        write_jsonl("{$fixture['codex_dir']}/rollout-multiple-standalone-identities.jsonl", [[
            'type' => 'session_meta',
            'payload' => [
                'id' => 'multiple-standalone-identities',
                'cwd' => $fixture['cwd'],
                'timestamp' => '2026-07-09T10:00:00Z',
                'base_instructions' => [
                    'text' => "Solo process ID: {$soloProcessId}\nSolo process ID: {$otherSoloProcessId}",
                ],
            ],
        ]]);

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($process->getExitCode())->toBeGreaterThan(0);

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'partial',
                'reason' => 'missing_primary_identity',
            ])
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toBeString()
            ->toContain('ownership=partial(no_primary_identity)');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('R2 lane A classifies Claude and Grok ownership before cardinality with bounded truthful diagnostics', function (string $provider): void {
    $soloProcessId = 979_920;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'foreign-diagnostics',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-foreign-diagnostics-{$provider}";
    $candidatePaths = [];

    try {
        foreach (range(1, 22) as $index) {
            $candidateCwd = "{$fixture['temp']}/foreign-worktree-{$index}";
            $candidatePaths[] = write_provider_capture_candidate(
                fixture: $fixture,
                provider: $provider,
                candidate: "foreign-{$index}",
                candidateCwd: $candidateCwd,
                candidateConfig: [
                    'marker_solo_process_id' => $soloProcessId,
                    'primary_solo_process_id' => $soloProcessId,
                ],
            );
        }

        $process = run_provider_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'no_owned_marker_transcript',
                'owned_candidates' => [],
            ])
            ->and($manifest['matched_candidates'])
            ->toHaveCount(20);

        foreach ($manifest['matched_candidates'] as $candidate) {
            expect($candidate)
                ->toHaveKeys(['path', 'ownership_class', 'normalized_cwd', 'primary_solo_process_id'])
                ->and($candidate['path'])
                ->toBeIn($candidatePaths)
                ->and($candidate['ownership_class'])
                ->toBe('foreign_cwd');
        }

        $diagnostics = $process->getErrorOutput().$process->getOutput();

        $expectedCwd = realpath($fixture['cwd']) ?: $fixture['cwd'];

        expect($diagnostics)
            ->toContain("expected_cwd={$expectedCwd}")
            ->toContain("observed_cwd={$fixture['temp']}/foreign-worktree-")
            ->toContain('lane spawned without a working-directory pin?');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 lane A records sole exact-cwd identity-less Claude and Grok candidates as partial', function (string $provider): void {
    $soloProcessId = 979_921;
    $fixture = make_provider_capture_fixture(provider: $provider, suffix: 'partial', soloProcessId: $soloProcessId);
    $slug = "r2-partial-{$provider}";

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'legacy-partial',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => null,
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray([
                'status' => 'partial',
                'reason' => 'missing_primary_identity',
            ])
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toBeString()
            ->toContain("provider={$provider}")
            ->toContain('ownership=partial(no_primary_identity)');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 lane A lets full Claude and Grok owners outrank partial and foreign candidates', function (string $provider): void {
    $soloProcessId = 979_922;
    $fixture = make_provider_capture_fixture(provider: $provider, suffix: 'full-owner', soloProcessId: $soloProcessId);
    $slug = "r2-full-owner-{$provider}";

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'partial',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => null,
            ],
        );
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'foreign-full',
            candidateCwd: "{$fixture['temp']}/foreign-worktree",
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
            ],
        );
        $fullOwner = write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'full-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok', 'artifact' => $fullOwner])
            ->and($manifest['disambiguation_basis'] ?? null)
            ->toContain('ownership=full');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 lane A refuses a colliding spawned-process row when the canonical process row is absent', function (): void {
    $soloProcessId = 979_923;
    $temp = make_agent_session_archive_temp_dir(suffix: 'r2-spawned-collision');
    $home = "{$temp}/home";
    $cwd = "{$temp}/worktree";
    $orbitDir = "{$temp}/.orbit";
    $soloDb = "{$temp}/solo.db";

    try {
        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec('CREATE TABLE processes (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec(
            'CREATE TABLE spawned_processes (id INTEGER PRIMARY KEY, pid INTEGER, process_name TEXT, command TEXT, project_path TEXT, spawned_at TEXT)',
        );
        $statement = $db->prepare(
            'INSERT INTO spawned_processes (id, pid, process_name, command, project_path, spawned_at) VALUES (?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([1, $soloProcessId, "colliding-{$soloProcessId}", 'codex', $cwd, '2026-07-10T12:00:00Z']);

        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            (string) $soloProcessId,
            "--home={$home}",
            "--cwd={$cwd}",
            "--solo-db={$soloDb}",
            "--orbit-dir={$orbitDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput())
            ->toContain('solo_process_not_found')
            ->and("{$orbitDir}/agent-sessions")
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('R2 lane A reports unknown lane-close and fallback commands as unsupported', function (): void {
    $soloProcessId = 979_924;
    $sensitiveCommand = 'mystery-agent --token=super-secret --prompt=private-data';
    $sensitiveFragments = ['mystery-agent', '--token', 'super-secret', '--prompt', 'private-data'];
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'r2-unsupported',
        soloProcessId: $soloProcessId,
        command: $sensitiveCommand,
    );
    $slug = 'r2-unsupported-command';

    try {
        $capture = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $captureManifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/unknown/{$slug}/manifest.json",
        );

        expect($capture->getExitCode())
            ->toBeGreaterThan(0)
            ->and($captureManifest)
            ->toMatchArray([
                'status' => 'unsupported',
                'reason' => 'unsupported_provider',
            ]);

        $capturePublicOutput = $capture->getErrorOutput().$capture->getOutput();
        $captureManifestJson = (string) file_get_contents(
            "{$fixture['orbit_dir']}/agent-sessions/unknown/{$slug}/manifest.json",
        );

        foreach ($sensitiveFragments as $sensitiveFragment) {
            expect($capturePublicOutput)
                ->not->toContain($sensitiveFragment)->and($captureManifestJson)
                ->not->toContain($sensitiveFragment);
        }

        $processesPath = "{$fixture['temp']}/processes.json";
        $archiveDir = "{$fixture['temp']}/archive";
        file_put_contents($processesPath, json_encode([[
            'id' => $soloProcessId,
            'name' => 'mystery-worker',
            'kind' => 'agent',
            'command' => $sensitiveCommand,
            'working_dir' => $fixture['cwd'],
        ]], JSON_THROW_ON_ERROR));

        $fallback = new Process(
            [
                repo_path('bin/orbit-agent-session-archive'),
                "--processes-json={$processesPath}",
                "--home={$fixture['home']}",
                "--cwd={$fixture['cwd']}",
                "--archive-dir={$archiveDir}",
            ],
            repo_path(),
            ['SOLO_PROCESS_ID' => false, 'SOLO_PROJECT_ID' => false],
        );
        $fallback->run();
        $results = json_decode($fallback->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($fallback->getExitCode())
            ->toBe(0, $fallback->getErrorOutput())
            ->and($results)
            ->toHaveCount(1)
            ->and($results[0])
            ->toMatchArray([
                'agent' => 'unknown',
                'status' => 'unsupported',
                'reason' => 'unsupported_provider',
            ]);

        $fallbackManifest = read_agent_session_archive_json(
            path: "{$archiveDir}/unknown/mystery-worker-{$soloProcessId}/manifest.json",
        );

        expect($fallbackManifest)->toMatchArray([
            'provider' => 'unknown',
            'status' => 'unsupported',
            'reason' => 'unsupported_provider',
        ]);

        $fallbackProviderManifestJson = (string) file_get_contents(
            "{$archiveDir}/unknown/mystery-worker-{$soloProcessId}/manifest.json",
        );
        $fallbackArchiveManifestJson = (string) file_get_contents("{$archiveDir}/manifest.json");

        foreach ($sensitiveFragments as $sensitiveFragment) {
            expect($fallback->getOutput())
                ->not->toContain($sensitiveFragment)->and($fallback->getErrorOutput())
                ->not->toContain($sensitiveFragment)->and($fallbackProviderManifestJson)
                ->not->toContain($sensitiveFragment)->and($fallbackArchiveManifestJson)
                ->not->toContain($sensitiveFragment);
        }
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('R2 lane A rejects required provider artifact symlinks without materializing external bytes', function (string $provider): void {
    $soloProcessId = 979_925;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'required-symlink',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-required-symlink-{$provider}";
    $external = "{$fixture['temp']}/external-required-{$provider}";

    try {
        file_put_contents($external, "external-secret-{$provider}\nSolo process ID: {$soloProcessId}\n");
        $symlink = write_provider_required_artifact_symlink(
            fixture: $fixture,
            provider: $provider,
            source: $external,
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );
        $rawDirectory = "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/raw";

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray(['status' => 'extraction_failed'])
            ->and($manifest['reason'])
            ->toContain('symlinked_required_artifact')
            ->toContain($symlink)
            ->and($rawDirectory)
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 review2 rejects symlinked provider ancestors below canonical home', function (string $provider): void {
    $soloProcessId = 979_940;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'provider-ancestor-symlink',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review2-provider-ancestor-{$provider}";
    $externalHome = "{$fixture['temp']}/external-home";
    $externalFixture = [
        ...$fixture,
        'home' => $externalHome,
        'codex_dir' => "{$externalHome}/.codex/sessions/2026/07/09",
    ];
    $providerAncestor = "{$fixture['home']}/.{$provider}";
    $externalProviderAncestor = "{$externalHome}/.{$provider}";

    try {
        if ($provider === 'codex') {
            mkdir($externalFixture['codex_dir'], recursive: true);
            write_incarnation_floor_rollout(
                fixture: $externalFixture,
                rolloutId: 'external-provider-owner',
                soloProcessId: $soloProcessId,
                activityRows: [],
            );
        } else {
            write_provider_capture_candidate(
                fixture: $externalFixture,
                provider: $provider,
                candidate: 'external-provider-owner',
                candidateCwd: $fixture['cwd'],
                candidateConfig: [
                    'marker_solo_process_id' => $soloProcessId,
                    'primary_solo_process_id' => $soloProcessId,
                ],
            );
        }

        $remove = new Process(['rm', '-rf', $providerAncestor]);
        $remove->run();
        expect($remove->getExitCode())->toBe(0, $remove->getErrorOutput());
        symlink($externalProviderAncestor, $providerAncestor);

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );
        $rawDirectory = "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/raw";

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray(['status' => 'extraction_failed'])
            ->and($manifest['reason'])
            ->toContain('symlinked_provider_source_component')
            ->toContain($providerAncestor)
            ->and($rawDirectory)
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['codex', 'claude', 'grok']);

it('R2 lane A omits optional Grok artifact symlinks and names them in manifest diagnostics', function (): void {
    $soloProcessId = 979_926;
    $fixture = make_provider_capture_fixture(
        provider: 'grok',
        suffix: 'optional-symlink',
        soloProcessId: $soloProcessId,
    );
    $slug = 'r2-optional-symlink-grok';

    try {
        $sessionDir = write_provider_capture_candidate(
            fixture: $fixture,
            provider: 'grok',
            candidate: 'full-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
            ],
        );
        $external = "{$fixture['temp']}/external-terminal.log";
        file_put_contents($external, "external-terminal-secret\n");
        symlink($external, "{$sessionDir}/terminal.log");

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/grok/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest['diagnostics'] ?? [])
            ->toContain("optional_symlink_artifact_omitted path={$sessionDir}/terminal.log")
            ->and("{$fixture['orbit_dir']}/agent-sessions/grok/{$slug}/raw/terminal.log")
            ->not->toBeFile();
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('R2 review keeps a nonempty unresolvable Solo row cwd authoritative', function (string $provider): void {
    $soloProcessId = 979_930;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'row-cwd-authority',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review-row-cwd-{$provider}";
    $rowCwd = "{$fixture['temp']}/nonexistent-row-worktree";

    try {
        $db = new PDO('sqlite:'.$fixture['solo_db']);
        $statement = $db->prepare('UPDATE processes SET working_dir = ? WHERE id = ?');
        $statement->execute([$rowCwd, $soloProcessId]);

        if ($provider === 'codex') {
            write_incarnation_floor_rollout(
                fixture: $fixture,
                rolloutId: 'row-cwd-authority',
                soloProcessId: $soloProcessId,
                activityRows: [],
            );
        } else {
            write_provider_capture_candidate(
                fixture: $fixture,
                provider: $provider,
                candidate: 'caller-cwd-owner',
                candidateCwd: $fixture['cwd'],
                candidateConfig: [
                    'marker_solo_process_id' => $soloProcessId,
                    'primary_solo_process_id' => $soloProcessId,
                ],
            );
        }

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'no_owned_marker_transcript',
            ])
            ->and($process->getErrorOutput())
            ->toContain("cwd={$rowCwd}");
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['codex', 'claude', 'grok']);

it('R2 review uses caller cwd only when the Solo row cwd is empty', function (): void {
    $soloProcessId = 979_931;
    $fixture = make_provider_capture_fixture(provider: 'codex', suffix: 'empty-row-cwd', soloProcessId: $soloProcessId);
    $slug = 'r2-review-empty-row-cwd';

    try {
        $db = new PDO('sqlite:'.$fixture['solo_db']);
        $statement = $db->prepare('UPDATE processes SET working_dir = ? WHERE id = ?');
        $statement->execute(['', $soloProcessId]);
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'empty-row-cwd',
            soloProcessId: $soloProcessId,
            activityRows: [],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok']);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('R2 review accepts agreeing real provider cwd shapes', function (string $provider): void {
    $soloProcessId = 979_932;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'cwd-agreement',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review-cwd-agreement-{$provider}";

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'agreeing-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok']);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 review accepts an exact provider root when the provider cwd field is absent', function (string $provider): void {
    $soloProcessId = 979_933;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'root-only-cwd',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review-root-only-cwd-{$provider}";

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'root-only-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
                'context_cwd' => null,
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok']);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 review rejects disagreement between provider root and provider cwd context', function (
    string $provider,
    string $direction,
): void {
    $soloProcessId = 979_934;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'cwd-disagreement',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review-cwd-disagreement-{$provider}";
    $foreignCwd = "{$fixture['temp']}/foreign-provider-root";

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'disagreeing-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
                'structural_cwd' => $direction === 'foreign-structural' ? $foreignCwd : $fixture['cwd'],
                'context_cwd' => $direction === 'foreign-context' ? $foreignCwd : $fixture['cwd'],
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray([
                'status' => 'missing',
                'reason' => 'no_owned_marker_transcript',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with([
    'Claude exact root and foreign row cwd' => ['claude', 'foreign-context'],
    'Claude foreign root and exact row cwd' => ['claude', 'foreign-structural'],
    'Grok exact root and foreign working_directory' => ['grok', 'foreign-context'],
    'Grok foreign root and exact working_directory' => ['grok', 'foreign-structural'],
]);

it('R2 review accepts the legacy Grok cwd prompt-context key when it agrees with the root', function (): void {
    $soloProcessId = 979_937;
    $fixture = make_provider_capture_fixture(provider: 'grok', suffix: 'legacy-cwd-key', soloProcessId: $soloProcessId);
    $slug = 'r2-review-grok-legacy-cwd-key';

    try {
        write_provider_capture_candidate(
            fixture: $fixture,
            provider: 'grok',
            candidate: 'legacy-cwd-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
                'context_key' => 'cwd',
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/grok/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok']);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('R2 review ignores foreign-root provider symlinks when an exact regular full owner exists', function (string $provider): void {
    $soloProcessId = 979_935;
    $fixture = make_provider_capture_fixture(
        provider: $provider,
        suffix: 'foreign-symlink',
        soloProcessId: $soloProcessId,
    );
    $slug = "r2-review-foreign-symlink-{$provider}";
    $foreignCwd = "{$fixture['temp']}/foreign-provider-root";
    $external = "{$fixture['temp']}/external-foreign-symlink-{$provider}";

    try {
        file_put_contents($external, "external-secret-{$provider}\nSolo process ID: {$soloProcessId}\n");
        write_provider_required_artifact_symlink(
            fixture: $fixture,
            provider: $provider,
            source: $external,
            structuralCwd: $foreignCwd,
        );
        $owner = write_provider_capture_candidate(
            fixture: $fixture,
            provider: $provider,
            candidate: 'exact-regular-owner',
            candidateCwd: $fixture['cwd'],
            candidateConfig: [
                'marker_solo_process_id' => $soloProcessId,
                'primary_solo_process_id' => $soloProcessId,
            ],
        );

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($manifest)
            ->toMatchArray(['status' => 'ok', 'artifact' => $owner])
            ->and("{$fixture['orbit_dir']}/agent-sessions/{$provider}/{$slug}/raw/".basename($external))
            ->not->toBeFile();
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with(['claude', 'grok']);

it('R2 review keeps Codex required-artifact symlinks globally fail-closed', function (): void {
    $soloProcessId = 979_936;
    $fixture = make_provider_capture_fixture(
        provider: 'codex',
        suffix: 'global-symlink',
        soloProcessId: $soloProcessId,
    );
    $slug = 'r2-review-codex-global-symlink';
    $external = "{$fixture['temp']}/external-codex-symlink";

    try {
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'valid-regular-owner',
            soloProcessId: $soloProcessId,
            activityRows: [],
        );
        file_put_contents($external, "external-secret-codex\nSolo process ID: {$soloProcessId}\n");
        symlink($external, "{$fixture['codex_dir']}/foreign-history.jsonl");

        $process = run_provider_capture(fixture: $fixture, soloProcessId: $soloProcessId, slug: $slug);
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toMatchArray(['status' => 'extraction_failed'])
            ->and($manifest['reason'])
            ->toContain('symlinked_required_artifact');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('exact marker join ignores parent-orchestrator transcript containing child marker (does not select parent for child)', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-parent');

    try {
        $home = "{$temp}/home";
        $cwd = "{$temp}/worktree";
        $orbitDir = "{$temp}/.orbit";
        $soloProcessId = 777777; // child id

        mkdir($home, recursive: true);
        mkdir($cwd, recursive: true);
        mkdir($orbitDir, recursive: true);

        $codexDir = "{$home}/.codex/sessions/2026/07/07";
        mkdir($codexDir, recursive: true);

        // Child's actual session rollout with marker in base_instructions
        write_jsonl("{$codexDir}/rollout-child-{$soloProcessId}.jsonl", [[
            'type' => 'session_meta',
            'payload' => [
                'id' => 'child-session',
                'cwd' => $cwd,
                'timestamp' => '2026-07-07T12:00:00Z',
                'base_instructions' => ['text' => "Child prompt\nSolo process ID: {$soloProcessId}"],
            ],
        ]]);

        // Parent orchestrator "transcript" (simulated as another jsonl in sessions dir) that logged the spawn and thus contains child's marker text
        write_jsonl("{$codexDir}/rollout-parent-orchestrator.jsonl", [[
            'type' => 'response_item',
            'payload' => [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => "spawned child Solo process ID: {$soloProcessId}"]],
            ],
        ]]);

        $soloDb = "{$temp}/solo.db";
        $db = new PDO('sqlite:'.$soloDb);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT)',
        );
        $stmt = $db->prepare(
            'INSERT INTO processes (id, project_id, name, command, working_dir, kind) VALUES (?, 4, ?, ?, ?, ?)',
        );
        $stmt->execute([$soloProcessId, 'child-worker', 'codex', $cwd, 'agent']);

        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            (string) $soloProcessId,
            "--home={$home}",
            "--cwd={$cwd}",
            "--solo-db={$soloDb}",
            "--orbit-dir={$orbitDir}",
            "--slug=child-exact-{$soloProcessId}",
        ], repo_path());

        $process->run();

        // Expect success with child's session (not the parent log), status ok, not ambiguous
        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->not->toContain('ambiguous');

        $manifestPath = "{$orbitDir}/agent-sessions/codex/child-exact-{$soloProcessId}/manifest.json";
        $manifest = json_decode((string) file_get_contents($manifestPath), true, JSON_THROW_ON_ERROR);
        expect($manifest['status'])->toBe('ok')->and($manifest['solo_process_id'])->toBe($soloProcessId);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it(
    'exact marker join disambiguates wrong cwd and stale starts with Solo metadata',
    function (): void {
        $temp = make_agent_session_archive_temp_dir(suffix: 'capture-edge');

        try {
            $home = "{$temp}/home";
            $cwd = "{$temp}/worktree";
            $orbitDir = "{$temp}/.orbit";
            $soloProcessId = 888888;

            mkdir($home, recursive: true);
            mkdir($cwd, recursive: true);
            mkdir($orbitDir, recursive: true);

            $codexDir = "{$home}/.codex/sessions/2026/07/07";
            mkdir($codexDir, recursive: true);

            // Stale rollout with marker but old time / wrong cwd
            write_jsonl("{$codexDir}/rollout-stale.jsonl", [[
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'stale',
                    'cwd' => '/wrong/old/cwd',
                    'timestamp' => '2020-01-01T00:00:00Z',
                    'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
                ],
            ]]);

            // Resumed/new with marker
            write_jsonl("{$codexDir}/rollout-resumed.jsonl", [[
                'type' => 'session_meta',
                'payload' => [
                    'id' => 'resumed',
                    'cwd' => $cwd,
                    'timestamp' => '2026-07-07T13:00:00Z',
                    'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
                ],
            ]]);

            $soloDb = "{$temp}/solo.db";
            $db = new PDO('sqlite:'.$soloDb);
            $db->exec(
                'CREATE TABLE IF NOT EXISTS processes (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, command TEXT, working_dir TEXT, kind TEXT, started_at TEXT)',
            );
            $stmt = $db->prepare(
                'INSERT INTO processes (id, project_id, name, command, working_dir, kind, started_at) VALUES (?, 4, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([
                $soloProcessId,
                'edge-worker',
                'codex',
                $cwd,
                'agent',
                '2026-07-07T12:59:55Z',
            ]);

            $process = new Process([
                repo_path('bin/orbit-agent-session-capture'),
                (string) $soloProcessId,
                "--home={$home}",
                "--cwd={$cwd}",
                "--solo-db={$soloDb}",
                "--orbit-dir={$orbitDir}",
                "--slug=edge-{$soloProcessId}",
            ], repo_path());

            $process->run();

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

            $manifestPath = "{$orbitDir}/agent-sessions/codex/edge-{$soloProcessId}/manifest.json";
            $manifest = json_decode((string) file_get_contents($manifestPath), true, JSON_THROW_ON_ERROR);

            expect($manifest['status'])
                ->toBe('ok')
                ->and($manifest['artifact'])
                ->toContain('rollout-resumed')
                ->and($manifest['disambiguation_basis'] ?? null)
                ->not->toBeEmpty();
        } finally {
            remove_agent_session_archive_temp_dir(path: $temp);
        }
    },
);

it('rejects malformed caller-attested incarnation floors before capture staging', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'capture-incarnation-floor-invalid');
    $orbitDir = "{$temp}/.orbit";

    try {
        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            '979797',
            "--home={$temp}/home",
            "--cwd={$temp}/worktree",
            "--solo-db={$temp}/missing-solo.db",
            "--orbit-dir={$orbitDir}",
            '--incarnation-started-at=2026-07-09 10:00:00',
        ], repo_path());

        $process->run();

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('incarnation-started-at')
            ->toContain('ISO-8601')
            ->not->toContain('solo db not found')->and("{$orbitDir}/agent-sessions")
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('rejects caller-attested incarnation floors for Grok before staging mutation', function (): void {
    $soloProcessId = 979_799;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'grok',
        soloProcessId: $soloProcessId,
        command: 'grok',
    );
    $slug = 'incarnation-floor-grok';
    $stagingDir = "{$fixture['orbit_dir']}/agent-sessions/grok/{$slug}";

    try {
        write_grok_fixture(
            home: $fixture['home'],
            cwd: $fixture['cwd'],
            marker: "Solo process ID: {$soloProcessId}",
        );
        mkdir($stagingDir, recursive: true);
        file_put_contents("{$stagingDir}/sentinel.txt", "preserve\n");

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
            incarnationStartedAt: '2026-07-09T10:10:00Z',
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toBe("incarnation_floor_unsupported_provider\n")
            ->and($process->getOutput())
            ->toBe('')
            ->and(glob("{$stagingDir}/*"))
            ->toBe(["{$stagingDir}/sentinel.txt"])
            ->and(file_get_contents("{$stagingDir}/sentinel.txt"))
            ->toBe("preserve\n");
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('rejects caller-attested incarnation floors for Claude before staging mutation', function (): void {
    $soloProcessId = 979_804;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: 'claude',
        soloProcessId: $soloProcessId,
        command: 'claude',
    );
    $slug = 'incarnation-floor-claude';
    $stagingDir = "{$fixture['orbit_dir']}/agent-sessions/claude/{$slug}";

    try {
        write_claude_project_dir_fixture(
            home: $fixture['home'],
            cwd: $fixture['cwd'],
            marker: "Solo process ID: {$soloProcessId}",
        );
        mkdir($stagingDir, recursive: true);
        file_put_contents("{$stagingDir}/sentinel.txt", "preserve\n");

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
            incarnationStartedAt: '2026-07-09T10:10:00Z',
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toBe("incarnation_floor_unsupported_provider\n")
            ->and($process->getOutput())
            ->toBe('')
            ->and(glob("{$stagingDir}/*"))
            ->toBe(["{$stagingDir}/sentinel.txt"])
            ->and(file_get_contents("{$stagingDir}/sentinel.txt"))
            ->toBe("preserve\n");
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it(
    'rejects Codex sessions without caller-attested incarnation activity at the floor',
    function (array $activityRows, ?string $expectedLastActivityAt, string $suffix): void {
        $soloProcessId = 979_800;
        $fixture = make_incarnation_floor_capture_fixture(suffix: $suffix, soloProcessId: $soloProcessId);
        $slug = "incarnation-floor-stale-{$suffix}";
        $floor = '2026-07-09T10:10:00Z';
        $rolloutId = "stale-{$suffix}";

        try {
            write_incarnation_floor_rollout(
                fixture: $fixture,
                rolloutId: $rolloutId,
                soloProcessId: $soloProcessId,
                activityRows: $activityRows,
                sessionMetaTimestamp: '2026-07-09T10:30:00Z',
            );

            $process = run_incarnation_floor_capture(
                fixture: $fixture,
                soloProcessId: $soloProcessId,
                slug: $slug,
                incarnationStartedAt: $floor,
            );

            expect($process->getExitCode())
                ->toBeGreaterThan(0)
                ->and($process->getErrorOutput().$process->getOutput())
                ->toContain('stale_pre_restart_session')
                ->toContain($floor)
                ->toContain($rolloutId)
                ->toContain('last_activity_at');

            $manifest = read_agent_session_archive_json(
                path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
            );

            expect($manifest)
                ->toMatchArray([
                    'status' => 'stale',
                    'reason' => 'stale_pre_restart_session',
                    'incarnation_floor' => $floor,
                    'incarnation_floor_source' => 'caller_attested',
                    'rollout_id' => $rolloutId,
                    'last_activity_at' => $expectedLastActivityAt,
                ]);
        } finally {
            remove_agent_session_archive_temp_dir(path: $fixture['temp']);
        }
    },
)->with([
    'canonical activity before floor' => [
        [[
            'timestamp' => '2026-07-09T10:05:00Z',
            'type' => 'response_item',
            'payload' => [
                'timestamp' => '2026-07-09T10:40:00Z',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Before the restart.']],
            ],
        ]],
        '2026-07-09T10:05:00Z',
        'before',
    ],
    'only session meta nested and malformed timestamps' => [
        [
            [
                'timestamp' => 'not-a-timestamp',
                'type' => 'response_item',
                'payload' => [
                    'timestamp' => '2026-07-09T10:40:00Z',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'Nested timestamps do not count.']],
                ],
            ],
            [
                'type' => 'event_msg',
                'payload' => [
                    'timestamp' => '2026-07-09T10:50:00Z',
                    'type' => 'turn_aborted',
                ],
            ],
        ],
        null,
        'excluded',
    ],
]);

it('accepts a unique Codex session with caller-attested incarnation activity at or after the floor', function (): void {
    $soloProcessId = 979_801;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'fresh', soloProcessId: $soloProcessId);
    $slug = 'incarnation-floor-fresh';
    $floor = '2026-07-09T10:10:00Z';
    $rolloutId = 'fresh-rollout';

    try {
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: $rolloutId,
            soloProcessId: $soloProcessId,
            activityRows: [
                [
                    'timestamp' => '2026-07-09T10:09:00Z',
                    'type' => 'response_item',
                    'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => []],
                ],
                [
                    'timestamp' => '2026-07-09T10:11:00Z',
                    'type' => 'event_msg',
                    'payload' => ['timestamp' => '2026-07-09T11:00:00Z', 'type' => 'turn_aborted'],
                ],
            ],
        );

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
            incarnationStartedAt: $floor,
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'ok',
                'incarnation_floor' => $floor,
                'incarnation_floor_source' => 'caller_attested',
                'rollout_id' => $rolloutId,
                'last_activity_at' => '2026-07-09T10:11:00Z',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('preserves lane-close capture output and manifest shape when no incarnation floor is supplied', function (): void {
    $soloProcessId = 979_802;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'legacy', soloProcessId: $soloProcessId);
    $slug = 'incarnation-floor-legacy';

    try {
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'legacy-rollout',
            soloProcessId: $soloProcessId,
            activityRows: [],
        );

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        $expectedOutput =
            json_encode([
                'status' => 'ok',
                'provider' => 'codex',
                'solo_process_id' => $soloProcessId,
                'slug' => $slug,
                'started_at' => '2026-07-09T09:00:00Z',
                'staging_dir' => "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}",
            ], JSON_UNESCAPED_SLASHES)."\n";

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toBe($expectedOutput);

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->not->toHaveKey('incarnation_floor')
            ->not->toHaveKey('incarnation_floor_source')
            ->not->toHaveKey('rollout_id')
            ->not->toHaveKey('last_activity_at');
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('keeps duplicate owned Codex sessions ambiguous when an incarnation floor is supplied', function (): void {
    $soloProcessId = 979_803;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'duplicate', soloProcessId: $soloProcessId);
    $slug = 'incarnation-floor-duplicate';

    try {
        foreach ([
            'stale' => '2026-07-09T10:05:00Z',
            'fresh' => '2026-07-09T10:15:00Z',
        ] as $rolloutId => $timestamp) {
            write_incarnation_floor_rollout(
                fixture: $fixture,
                rolloutId: $rolloutId,
                soloProcessId: $soloProcessId,
                activityRows: [[
                    'timestamp' => $timestamp,
                    'type' => 'event_msg',
                    'payload' => ['type' => 'turn_aborted'],
                ]],
            );
        }

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
            incarnationStartedAt: '2026-07-09T10:10:00Z',
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput().$process->getOutput())
            ->toContain('ambiguous_duplicate_markers')
            ->not->toContain('stale_pre_restart_session');

        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($manifest)
            ->toMatchArray([
                'status' => 'ambiguous',
                'reason' => 'ambiguous_duplicate_markers',
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 3 staging replacement rejects invalid explicit slugs before DB access or staging', function (string $slugArgument): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'stage-3-invalid-slug');
    $orbitDir = "{$temp}/.orbit";

    try {
        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            '979901',
            "--home={$temp}/home",
            "--cwd={$temp}/worktree",
            "--solo-db={$temp}/missing-solo.db",
            "--orbit-dir={$orbitDir}",
            "--slug={$slugArgument}",
        ], repo_path());

        $process->run();

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toBe("invalid_explicit_slug\n")
            ->not->toContain('solo db not found')->and($process->getOutput())->toBe('')->and(
                "{$orbitDir}/agent-sessions",
            )
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
})->with([
    'empty' => '',
    'requires lowercasing' => 'Stage-Three',
    'requires separator rewriting' => 'stage three',
    'requires repeated-separator collapse' => 'stage--three',
    'requires edge trimming' => '-stage-three-',
    'rejects path traversal' => '../escape',
]);

it('stage 3 staging replacement replaces same-slug success with only new coherent artifacts', function (): void {
    $soloProcessId = 979_902;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'stage-3-success-success', soloProcessId: $soloProcessId);
    $slug = 'stage-3-success-success';
    $providerRoot = "{$fixture['orbit_dir']}/agent-sessions/codex";
    $finalDir = "{$providerRoot}/{$slug}";

    try {
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'first-success',
            soloProcessId: $soloProcessId,
            activityRows: [[
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'First success.']],
                ],
            ]],
        );

        $first = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput());

        unlink("{$fixture['codex_dir']}/rollout-first-success.jsonl");
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'second-success',
            soloProcessId: $soloProcessId,
            activityRows: [[
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'Second success.']],
                ],
            ]],
        );

        $second = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($second->getExitCode())->toBe(0, $second->getErrorOutput().$second->getOutput());

        $rawFiles = array_map('basename', glob("{$finalDir}/raw/*") ?: []);
        $finalEntries = array_map('basename', glob("{$finalDir}/*") ?: []);
        sort($rawFiles);
        sort($finalEntries);

        expect(dirname($finalDir))
            ->toBe($providerRoot)
            ->and($finalEntries)
            ->toBe(['manifest.json', 'messages.jsonl', 'raw', 'usage.json'])
            ->and($rawFiles)
            ->toBe(['rollout-second-success.jsonl'])
            ->and("{$finalDir}/raw/rollout-first-success.jsonl")
            ->not->toBeFile()->and(file_get_contents("{$finalDir}/messages.jsonl"))->toContain('Second success.')
            ->not->toContain('First success.')->and(glob("{$providerRoot}/.{$slug}.tmp-*") ?: [])->toBe([])->and(
                glob("{$providerRoot}/.{$slug}.backup-*") ?: [],
            )->toBe([]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('stage 3 staging replacement replaces same-slug success with one coherent failure capture', function (): void {
    $soloProcessId = 979_903;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'stage-3-success-failure', soloProcessId: $soloProcessId);
    $slug = 'stage-3-success-failure';
    $providerRoot = "{$fixture['orbit_dir']}/agent-sessions/codex";
    $finalDir = "{$providerRoot}/{$slug}";

    try {
        write_incarnation_floor_rollout(
            fixture: $fixture,
            rolloutId: 'success-before-failure',
            soloProcessId: $soloProcessId,
            activityRows: [[
                'type' => 'response_item',
                'payload' => [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'Success before failure.']],
                ],
            ]],
        );

        $success = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($success->getExitCode())->toBe(0, $success->getErrorOutput().$success->getOutput());

        unlink("{$fixture['codex_dir']}/rollout-success-before-failure.jsonl");

        $failure = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );

        expect($failure->getExitCode())
            ->toBeGreaterThan(0)
            ->and($failure->getErrorOutput().$failure->getOutput())
            ->toContain('exact_marker_not_found');

        $finalEntries = array_map('basename', glob("{$finalDir}/*") ?: []);
        sort($finalEntries);

        expect(dirname($finalDir))
            ->toBe($providerRoot)
            ->and($finalEntries)
            ->toBe(['manifest.json'])
            ->and("{$finalDir}/usage.json")
            ->not->toBeFile()->and("{$finalDir}/messages.jsonl")
            ->not->toBeFile()->and("{$finalDir}/raw")
            ->not->toBeDirectory()->and(glob("{$providerRoot}/.{$slug}.tmp-*") ?: [])->toBe([])->and(
                glob("{$providerRoot}/.{$slug}.backup-*") ?: [],
            )->toBe([]);

        $manifest = read_agent_session_archive_json(path: "{$finalDir}/manifest.json");

        expect($manifest)
            ->toBe([
                'schema_version' => 1,
                'provider' => 'codex',
                'status' => 'missing',
                'slug' => $slug,
                'solo_process_id' => $soloProcessId,
                'kind' => 'agent',
                'started_at' => '2026-07-09T09:00:00Z',
                'reason' => 'exact_marker_not_found',
                'checked' => [],
                'marker' => "Solo process ID: {$soloProcessId}",
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it(
    'stage 3 staging replacement proves deterministic replacement and rollback behavior',
    function (string $scenario, array $expected): void {
        $temp = make_agent_session_archive_temp_dir(suffix: "stage-3-replacement-{$scenario}");

        try {
            $process = run_staged_capture_replacement_scenario(root: $temp, scenario: $scenario);

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

            $result = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
            $expectedState = $expected;
            unset($expectedState['error_contains']);

            expect($result)->toMatchArray($expectedState);

            foreach ($expected['error_contains'] ?? [] as $fragment) {
                expect($result['error'])->toContain($fragment);
            }
        } finally {
            remove_agent_session_archive_temp_dir(path: $temp);
        }
    },
)->with([
    'final to backup failure' => [
        'first-rename-fails',
        [
            'final_old' => true,
            'final_new' => false,
            'temp_exists' => true,
            'backup_exists' => false,
            'rename_calls' => 1,
            'error_contains' => ['final_to_backup_failed', '/temp', '/final', '/backup'],
        ],
    ],
    'temp to final failure rolls back' => [
        'second-rename-fails',
        [
            'final_old' => true,
            'final_new' => false,
            'temp_exists' => false,
            'backup_exists' => false,
            'rename_calls' => 3,
            'error_contains' => ['temp_to_final_failed_rolled_back', '/temp', '/final', '/backup'],
        ],
    ],
    'rollback rename also fails loudly' => [
        'rollback-rename-fails',
        [
            'final_old' => false,
            'final_new' => false,
            'temp_exists' => true,
            'backup_exists' => true,
            'backup_old' => true,
            'rename_calls' => 3,
            'error_contains' => ['temp_to_final_and_rollback_failed', '/temp', '/final', '/backup'],
        ],
    ],
    'native same-filesystem success' => [
        'native-success',
        [
            'final_old' => false,
            'final_new' => true,
            'temp_exists' => false,
            'backup_exists' => false,
            'rename_calls' => 0,
            'error' => null,
        ],
    ],
]);

it('stage 3 staging replacement rejects non-sibling paths before any rename', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'stage-3-replacement-non-sibling');

    try {
        $process = run_staged_capture_replacement_scenario(root: $temp, scenario: 'non-sibling');

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $result = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['rename_calls'])
            ->toBe(0)
            ->and($result['error'])
            ->toContain('not_direct_child')
            ->toContain('/nested/temp');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

function run_staged_capture_replacement_scenario(string $root, string $scenario): Process
{
    $include = repo_path('bin/orbit-agent-session-capture-filesystem.php');
    $code = <<<'PHP'
        require_once $argv[1];

        $root = realpath($argv[2]) ?: $argv[2];
        $scenario = $argv[3];
        $temp = $scenario === 'non-sibling' ? "{$root}/nested/temp" : "{$root}/temp";
        $final = "{$root}/final";
        $backup = "{$root}/backup";

        mkdir($temp, recursive: true);
        mkdir($final, recursive: true);
        file_put_contents("{$temp}/new.txt", "new\n");
        file_put_contents("{$final}/old.txt", "old\n");

        $renameCalls = 0;
        $rename = null;

        if ($scenario !== 'native-success') {
            $rename = function (string $from, string $to) use ($scenario, &$renameCalls): bool {
                $renameCalls++;

                if ($scenario === 'first-rename-fails' && $renameCalls === 1) {
                    return false;
                }

                if ($scenario === 'second-rename-fails' && $renameCalls === 2) {
                    return false;
                }

                if ($scenario === 'rollback-rename-fails' && $renameCalls >= 2) {
                    return false;
                }

                return rename($from, $to);
            };
        }

        $error = null;

        try {
            orbitAgentSessionCaptureReplaceStagedDirectory($root, $temp, $final, $backup, $rename);
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        echo json_encode([
            'final_old' => is_file("{$final}/old.txt"),
            'final_new' => is_file("{$final}/new.txt"),
            'temp_exists' => is_dir($temp),
            'backup_exists' => is_dir($backup),
            'backup_old' => is_file("{$backup}/old.txt"),
            'rename_calls' => $renameCalls,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $code, $include, $root, $scenario], repo_path());
    $process->run();

    return $process;
}

it('review corrections reject invalid explicit providers before DB access or staging', function (string $provider): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-invalid-provider');
    $orbitDir = "{$temp}/.orbit";

    try {
        $process = new Process([
            repo_path('bin/orbit-agent-session-capture'),
            '979911',
            "--home={$temp}/home",
            "--cwd={$temp}/worktree",
            "--solo-db={$temp}/missing-solo.db",
            "--orbit-dir={$orbitDir}",
            "--provider={$provider}",
        ], repo_path());

        $process->run();

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toBe("invalid_explicit_provider\n")
            ->not->toContain('solo db not found')->and("{$orbitDir}/agent-sessions")
            ->not->toBeDirectory();
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
})->with([
    'parent traversal' => '../x',
    'nested path' => 'a/b',
]);

it('review corrections reject a symlinked provider directory without touching its target', function (): void {
    $soloProcessId = 979_912;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'review-provider-symlink', soloProcessId: $soloProcessId);
    $agentSessionsRoot = "{$fixture['orbit_dir']}/agent-sessions";
    $external = "{$fixture['temp']}/external-provider";
    $sentinel = "{$external}/sentinel.txt";

    try {
        mkdir($agentSessionsRoot, recursive: true);
        mkdir($external, recursive: true);
        file_put_contents($sentinel, "preserve\n");
        symlink($external, "{$agentSessionsRoot}/codex");

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: 'review-provider-symlink',
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput())
            ->toContain('symlinked_provider_root')
            ->and($sentinel)
            ->toBeFile()
            ->and(file_get_contents($sentinel))
            ->toBe("preserve\n");
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('review corrections reject a symlinked agent sessions root without touching its target', function (): void {
    $soloProcessId = 979_913;
    $fixture = make_incarnation_floor_capture_fixture(suffix: 'review-root-symlink', soloProcessId: $soloProcessId);
    $external = "{$fixture['temp']}/external-agent-sessions";
    $sentinel = "{$external}/sentinel.txt";

    try {
        mkdir($external, recursive: true);
        file_put_contents($sentinel, "preserve\n");
        symlink($external, "{$fixture['orbit_dir']}/agent-sessions");

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: 'review-root-symlink',
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($process->getErrorOutput())
            ->toContain('symlinked_agent_sessions_root')
            ->and($sentinel)
            ->toBeFile()
            ->and(file_get_contents($sentinel))
            ->toBe("preserve\n");
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
});

it('review corrections clean an incomplete temp when a write callable fails mid build', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-write-failure');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'write-fails-mid-build');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'write_calls' => 2,
                'copy_calls' => 0,
            ])
            ->and($result['error'])
            ->toContain('write_failed')
            ->toContain('/temp/usage.json');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('review corrections clean an incomplete temp when a copy callable fails', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-copy-failure');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'copy-fails');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'write_calls' => 3,
                'copy_calls' => 1,
            ])
            ->and($result['error'])
            ->toContain('copy_failed')
            ->toContain('/source.jsonl')
            ->toContain('/temp/raw/source.jsonl');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('R2 review pins raw copy bytes when the source path changes after its regular-file check', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'r2-review-pinned-raw-copy');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'source-replaced-before-copy');

        expect($result)
            ->toMatchArray([
                'temp_exists' => true,
                'raw_value' => "raw\n",
                'source_is_link' => true,
                'external_sentinel_value' => "external-secret\n",
                'error' => null,
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('root review rejects a declared missing raw source and cleans the incomplete temp', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'root-review-missing-raw');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'missing-raw-source');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'escaped_raw_exists' => false,
            ])
            ->and($result['error'])
            ->toContain('raw_source_missing')
            ->toContain('/source.jsonl');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('root review rejects a non-basename raw archive name before it can escape raw', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'root-review-raw-name');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'invalid-raw-archive-name');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'escaped_raw_exists' => false,
            ])
            ->and($result['error'])
            ->toContain('invalid_raw_archive_name')
            ->toContain('../escaped.jsonl');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('review corrections check a false native write and never install an incomplete capture', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-native-write-false');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'native-write-false');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'write_calls' => 0,
                'copy_calls' => 0,
            ])
            ->and($result['error'])
            ->toContain('write_failed')
            ->toContain('/temp/manifest.json');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('review corrections build a complete capture with native writes and copies', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-native-success');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'native-success');

        expect($result)
            ->toMatchArray([
                'temp_exists' => true,
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
                'write_calls' => 0,
                'copy_calls' => 0,
                'error' => null,
                'temp_entries' => ['manifest.json', 'messages.jsonl', 'raw', 'usage.json'],
                'raw_value' => "raw\n",
            ]);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('review corrections reassert canonical containment at recursive delete without touching an external sentinel', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'review-delete-containment');
    $providerRoot = "{$temp}/agent-sessions/codex";
    $external = "{$temp}/external/nested";
    $sentinel = "{$external}/sentinel.txt";
    $include = repo_path('bin/orbit-agent-session-capture-filesystem.php');
    $code = <<<'PHP'
        require_once $argv[1];

        $path = $argv[2];
        $canonicalProviderRoot = $argv[3];
        $error = null;

        try {
            orbitAgentSessionCaptureRemovePathRecursively($path, $canonicalProviderRoot);
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        echo json_encode([
            'error' => $error,
            'sentinel_exists' => is_file("{$path}/sentinel.txt"),
            'sentinel_value' => file_get_contents("{$path}/sentinel.txt"),
        ], JSON_UNESCAPED_SLASHES);
        PHP;

    try {
        mkdir($providerRoot, recursive: true);
        mkdir($external, recursive: true);
        file_put_contents($sentinel, "preserve\n");
        $providerRoot = realpath($providerRoot) ?: $providerRoot;
        $external = realpath($external) ?: $external;
        $sentinel = "{$external}/sentinel.txt";

        $process = new Process([PHP_BINARY, '-r', $code, $include, $external, $providerRoot], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $result = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result)
            ->toMatchArray([
                'sentinel_exists' => true,
                'sentinel_value' => "preserve\n",
            ])
            ->and($result['error'])
            ->toContain('not_direct_child')
            ->toContain($external)
            ->toContain($providerRoot);
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('root review rejects a noncanonical symlinked provider root without touching its target', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'root-review-provider-root-canonical');
    $realProviderRoot = "{$temp}/real-provider";
    $symlinkedProviderRoot = "{$temp}/provider-link";
    $candidate = "{$symlinkedProviderRoot}/candidate";
    $sentinel = "{$realProviderRoot}/candidate/sentinel.txt";
    $include = repo_path('bin/orbit-agent-session-capture-filesystem.php');
    $code = <<<'PHP'
        require_once $argv[1];

        $error = null;

        try {
            orbitAgentSessionCaptureRemovePathRecursively($argv[2], $argv[3]);
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES);
        PHP;

    try {
        mkdir("{$realProviderRoot}/candidate", recursive: true);
        file_put_contents($sentinel, "preserve\n");
        symlink($realProviderRoot, $symlinkedProviderRoot);

        $process = new Process([
            PHP_BINARY,
            '-r',
            $code,
            $include,
            $candidate,
            $symlinkedProviderRoot,
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $result = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect((string) ($result['error'] ?? ''))->toContain('provider_root_not_canonical');
        expect($sentinel)->toBeFile();
        expect(file_get_contents($sentinel))->toBe("preserve\n");
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('root review rejects and unlinks a temp symlink without touching its target', function (): void {
    $temp = make_agent_session_archive_temp_dir(suffix: 'root-review-temp-symlink');

    try {
        $result = run_capture_build_scenario(root: $temp, scenario: 'temp-symlink');

        expect($result)
            ->toMatchArray([
                'temp_exists' => false,
                'temp_link_exists' => false,
                'external_sentinel_exists' => true,
                'external_sentinel_value' => "preserve\n",
                'final_value' => "final-old\n",
                'backup_value' => "backup-old\n",
            ])
            ->and($result['error'])
            ->toContain('symlinked_temporary_staging');
    } finally {
        remove_agent_session_archive_temp_dir(path: $temp);
    }
});

it('review corrections expose bounded actual Codex ownership diagnostics while retaining checked', function (string $scenario): void {
    $soloProcessId = 979_914;
    $fixture = make_incarnation_floor_capture_fixture(
        suffix: "review-diagnostics-{$scenario}",
        soloProcessId: $soloProcessId,
    );
    $slug = "review-diagnostics-{$scenario}";

    try {
        foreach (range(1, 25) as $index) {
            write_jsonl("{$fixture['codex_dir']}/noise-{$index}.jsonl", [[
                'type' => 'session_meta',
                'payload' => ['id' => "noise-{$index}", 'cwd' => $fixture['cwd']],
            ]]);
        }

        $candidateCwd = $scenario === 'ambiguous' ? $fixture['cwd'] : "{$fixture['temp']}/foreign-worktree";
        $candidatePaths = [];

        $rolloutIds = $scenario === 'ambiguous'
            ? array_map(static fn (int $index): string => "actual-{$index}", range(1, 22))
            : ['actual-a', 'actual-b'];

        foreach ($rolloutIds as $rolloutId) {
            $candidatePath = "{$fixture['codex_dir']}/rollout-{$rolloutId}.jsonl";
            $candidatePaths[] = $candidatePath;
            write_jsonl($candidatePath, [[
                'type' => 'session_meta',
                'payload' => [
                    'id' => $rolloutId,
                    'cwd' => $candidateCwd,
                    'base_instructions' => ['text' => "Solo process ID: {$soloProcessId}"],
                ],
            ]]);
        }

        $process = run_incarnation_floor_capture(
            fixture: $fixture,
            soloProcessId: $soloProcessId,
            slug: $slug,
        );
        $manifest = read_agent_session_archive_json(
            path: "{$fixture['orbit_dir']}/agent-sessions/codex/{$slug}/manifest.json",
        );

        expect($process->getExitCode())
            ->toBeGreaterThan(0)
            ->and($manifest)
            ->toHaveKey('checked')
            ->toHaveKey('matched_candidates')
            ->toHaveKey('owned_candidates')
            ->and($manifest['matched_candidates'])
            ->toHaveCount($scenario === 'ambiguous' ? 20 : 2)
            ->and($manifest['owned_candidates'])
            ->toHaveCount($scenario === 'ambiguous' ? 20 : 0)
            ->and(count($manifest['matched_candidates']))
            ->toBeLessThanOrEqual(20)
            ->and(count($manifest['owned_candidates']))
            ->toBeLessThanOrEqual(20);

        foreach ($manifest['matched_candidates'] as $candidate) {
            expect($candidate)
                ->toHaveKeys(['path', 'ownership_class', 'normalized_cwd', 'primary_solo_process_id'])
                ->and($candidate['path'])
                ->toBeIn($candidatePaths);
        }

        foreach ($manifest['owned_candidates'] as $candidate) {
            expect($candidate['ownership_class'])->toBe('full');
        }

        $diagnostics = $process->getErrorOutput().$process->getOutput();

        foreach ($manifest['matched_candidates'] as $candidate) {
            $candidatePath = $candidate['path'];
            expect($diagnostics)->toContain($candidatePath);
        }
    } finally {
        remove_agent_session_archive_temp_dir(path: $fixture['temp']);
    }
})->with([
    'ambiguous owned candidates' => 'ambiguous',
    'no owned candidates' => 'no-owned',
]);

it('review corrections include is idempotent beside a predeclared generic main without executing the CLI', function (): void {
    $include = repo_path('bin/orbit-agent-session-capture-filesystem.php');
    $code = <<<'PHP'
        function main(): string
        {
            return 'generic-main';
        }

        require_once $argv[1];
        require_once $argv[1];

        echo main().'|'.(function_exists('orbitAgentSessionCaptureBuildStagingDirectory') ? 'loaded' : 'missing');
        PHP;
    $process = new Process([PHP_BINARY, '-r', $code, $include], repo_path());

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getOutput())
        ->toBe('generic-main|loaded');
});

/** @return array<string, mixed> */
function run_capture_build_scenario(string $root, string $scenario): array
{
    $include = repo_path('bin/orbit-agent-session-capture-filesystem.php');
    $code = <<<'PHP'
        require_once $argv[1];

        $root = realpath($argv[2]) ?: $argv[2];
        $scenario = $argv[3];
        $providerRoot = "{$root}/provider";
        $temp = "{$providerRoot}/temp";
        $final = "{$providerRoot}/final";
        $backup = "{$providerRoot}/backup";
        $source = "{$root}/source.jsonl";

        mkdir($final, recursive: true);
        mkdir($backup, recursive: true);
        $providerRoot = realpath($providerRoot) ?: $providerRoot;
        $temp = "{$providerRoot}/temp";
        $final = "{$providerRoot}/final";
        $backup = "{$providerRoot}/backup";
        file_put_contents("{$final}/sentinel.txt", "final-old\n");
        file_put_contents("{$backup}/sentinel.txt", "backup-old\n");
        file_put_contents($source, "raw\n");

        if ($scenario === 'missing-raw-source') {
            unlink($source);
        }

        $externalTarget = "{$root}/external-target";

        if ($scenario === 'temp-symlink') {
            mkdir($externalTarget, recursive: true);
            file_put_contents("{$externalTarget}/sentinel.txt", "preserve\n");
            symlink($externalTarget, $temp);
        }

        if ($scenario === 'source-replaced-before-copy') {
            mkdir($externalTarget, recursive: true);
            file_put_contents("{$externalTarget}/sentinel.txt", "external-secret\n");
        }

        if ($scenario === 'native-write-false') {
            mkdir("{$temp}/manifest.json", recursive: true);
        }

        $writeCalls = 0;
        $copyCalls = 0;
        $write = null;
        $copy = null;

        if ($scenario === 'write-fails-mid-build') {
            $write = function (string $path, string $contents) use (&$writeCalls): int|false {
                $writeCalls++;

                if ($writeCalls === 2) {
                    return false;
                }

                return file_put_contents($path, $contents);
            };
        }

        if ($scenario === 'copy-fails') {
            $write = function (string $path, string $contents) use (&$writeCalls): int|false {
                $writeCalls++;

                return file_put_contents($path, $contents);
            };
            $copy = function (mixed $source, string $destination) use (&$copyCalls): bool {
                $copyCalls++;

                return false;
            };
        }

        if ($scenario === 'source-replaced-before-copy') {
            $copy = function (mixed $sourceHandle, string $destination) use (
                &$copyCalls,
                $source,
                $externalTarget,
            ): bool {
                $copyCalls++;
                unlink($source);
                symlink("{$externalTarget}/sentinel.txt", $source);

                if (is_resource($sourceHandle)) {
                    $destinationHandle = fopen($destination, 'wb');

                    if ($destinationHandle === false) {
                        return false;
                    }

                    try {
                        return stream_copy_to_stream($sourceHandle, $destinationHandle) !== false;
                    } finally {
                        fclose($destinationHandle);
                    }
                }

                return copy($sourceHandle, $destination);
            };
        }

        $error = null;

        try {
            orbitAgentSessionCaptureBuildStagingDirectory(
                canonicalProviderRoot: $providerRoot,
                temporaryStaging: $temp,
                manifest: ['status' => 'ok'],
                usage: ['input_tokens' => 1],
                messagesJsonl: "{\"role\":\"assistant\"}\n",
                rawFiles: [[
                    'path' => $source,
                    'archive_name' => $scenario === 'invalid-raw-archive-name' ? '../escaped.jsonl' : 'source.jsonl',
                ]],
                write: $write,
                copy: $copy,
            );
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        $tempEntries = [];

        if (is_dir($temp)) {
            $tempEntries = array_map('basename', glob("{$temp}/*") ?: []);
            sort($tempEntries);
        }

        echo json_encode([
            'temp_exists' => is_dir($temp),
            'temp_link_exists' => is_link($temp),
            'final_value' => file_get_contents("{$final}/sentinel.txt"),
            'backup_value' => file_get_contents("{$backup}/sentinel.txt"),
            'write_calls' => $writeCalls,
            'copy_calls' => $copyCalls,
            'error' => $error,
            'temp_entries' => $tempEntries,
            'raw_value' => is_file("{$temp}/raw/source.jsonl") ? file_get_contents("{$temp}/raw/source.jsonl") : null,
            'source_is_link' => is_link($source),
            'escaped_raw_exists' => is_file("{$temp}/escaped.jsonl"),
            'external_sentinel_exists' => is_file("{$externalTarget}/sentinel.txt"),
            'external_sentinel_value' => is_file("{$externalTarget}/sentinel.txt")
                ? file_get_contents("{$externalTarget}/sentinel.txt")
                : null,
        ], JSON_UNESCAPED_SLASHES);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $code, $include, $root, $scenario], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
}
