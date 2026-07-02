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
