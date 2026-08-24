<?php

declare(strict_types=1);

it('points Laravel Boost MCP at the relocated gateway artisan', function (): void {
    $config = json_decode(
        (string) file_get_contents(repo_path('.mcp.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($config['mcpServers']['laravel-boost']['command'])
        ->toBe('php')
        ->and($config['mcpServers']['laravel-boost']['args'])
        ->toBe([
            'apps/gateway/artisan',
            'boost:mcp',
        ]);
});

it('points Codex Laravel Boost MCP at the relocated gateway artisan', function (): void {
    $config = file_get_contents(repo_path('.codex/config.toml')) ?: '';

    expect($config)
        ->toContain('args = ["apps/gateway/artisan", "boost:mcp"]')
        ->not->toContain('args = ["artisan", "boost:mcp"]')
        ->not->toContain('cwd = ".."');
});

it('exposes the isolated Orbit docs QMD MCP endpoint in root agent configs', function (): void {
    $claudeConfig = json_decode(
        (string) file_get_contents(repo_path('.mcp.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $codexConfig = file_get_contents(repo_path('.codex/config.toml')) ?: '';

    expect($claudeConfig['mcpServers']['qmd-orbit'] ?? null)
        ->toBe([
            'type' => 'http',
            'url' => 'http://10.6.0.7:8182/mcp',
        ])
        ->and($codexConfig)
        ->toContain('[mcp_servers.qmd-orbit]')
        ->toContain('url = "http://10.6.0.7:8182/mcp"')
        ->not->toContain('url = "http://10.6.0.7:8181/mcp"');
});

it('targets monorepo root agent artifacts from gateway boost config', function (): void {
    $repoRoot = realpath(repo_path());
    $codex = config('boost.agents.codex');
    $claudeCode = config('boost.agents.claude_code');

    expect(realpath((string) config('boost.executable_paths.current_directory')))
        ->toBe($repoRoot)
        ->and(realpath((string) $codex['guidelines_path']))
        ->toBe(realpath(repo_path('AGENTS.md')))
        ->and(realpath((string) $codex['mcp_config_path']))
        ->toBe(realpath(repo_path('.codex/config.toml')))
        ->and(realpath(base_path((string) $codex['skills_path'])))
        ->toBe(realpath(repo_path('.agents/skills')))
        ->and(realpath((string) $claudeCode['guidelines_path']))
        ->toBe(realpath(repo_path('CLAUDE.md')))
        ->and(realpath((string) $claudeCode['mcp_config_path']))
        ->toBe(realpath(repo_path('.mcp.json')))
        ->and(realpath(base_path((string) $claudeCode['skills_path'])))
        ->toBe(realpath(repo_path('.agents/skills')));
});

it('keeps gateway composer post-update maintenance on boost update wrapper', function (): void {
    $composer = json_decode(
        (string) file_get_contents(repo_path('apps/gateway/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $commands = $composer['scripts']['post-update-cmd'];

    expect($commands)
        ->toContain('@php artisan vendor:publish --tag=laravel-assets --ansi --force')
        ->toContain('../../bin/orbit-boost-update')
        ->and(implode("\n", $commands))
        ->toContain('orbit-boost-update')
        ->not->toContain('boost:install');
});

it('exposes a root boost update wrapper that runs gateway boost update', function (): void {
    $script = file_get_contents(repo_path('bin/orbit-boost-update')) ?: '';

    expect(repo_path('bin/orbit-boost-update'))
        ->toBeFile()
        ->and(is_executable(repo_path('bin/orbit-boost-update')))
        ->toBeTrue()
        ->and($script)
        ->toContain('orbit_repo_root')
        ->toContain('APP_ENV="${APP_ENV:-local}"')
        ->toContain('APP_DEBUG="${APP_DEBUG:-true}"')
        ->toContain('boost:update')
        ->toContain('--no-interaction')
        ->not->toContain('boost:install');
});

it('tracks monorepo boost packages and skills in gateway boost json', function (): void {
    $boost = json_decode(
        (string) file_get_contents(repo_path('apps/gateway/boost.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($boost['packages'])
        ->toContain('hardimpactdev/librarian')
        ->toContain('hardimpactdev/orbit-core')
        ->toContain('hardimpactdev/orbit-sdk-laravel')
        ->and($boost['skills'])
        ->toContain('librarian')
        ->toContain('orbit-core-development')
        ->toContain('orbit-sdk-development')
        ->toContain('orbit-cli-development')
        ->toContain('orbit-gateway-development')
        ->toContain('orbit-docs-development');
});

it('keeps Laravel Boost installed only in the gateway app', function (): void {
    $rootComposer = json_decode(
        (string) file_get_contents(repo_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $gatewayComposer = json_decode(
        (string) file_get_contents(repo_path('apps/gateway/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($gatewayComposer['require-dev'])
        ->toHaveKey('laravel/boost')
        ->and($rootComposer['require'] ?? [])
        ->not->toHaveKey('laravel/boost')->and($rootComposer['require-dev'] ?? [])
        ->not->toHaveKey('laravel/boost');
});

it('keeps expected monorepo boost skill directories at the repo root', function (): void {
    foreach ([
        '.agents/skills/librarian/SKILL.md',
        '.agents/skills/orbit-core-development/SKILL.md',
        '.agents/skills/orbit-sdk-development/SKILL.md',
        '.agents/skills/orbit-cli-development/SKILL.md',
        '.agents/skills/orbit-gateway-development/SKILL.md',
        '.agents/skills/orbit-docs-development/SKILL.md',
    ] as $skillPath) {
        expect(repo_path($skillPath))->toBeFile();
    }
});

it('keeps the app-owned orbit skill in the agents skill catalog', function (): void {
    $codex = config('boost.agents.codex');
    $claudeCode = config('boost.agents.claude_code');

    expect(repo_path('.agents/skills/orbit/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('.agents/skills/orbit/references/concepts.md'))
        ->toBeFile()
        ->and(repo_path('skills/orbit'))
        ->not
        ->toBeDirectory()
        ->and(realpath(base_path((string) $codex['skills_path'])))
        ->toBe(realpath(repo_path('.agents/skills')))
        ->and(realpath(base_path((string) $claudeCode['skills_path'])))
        ->toBe(realpath(repo_path('.agents/skills')));
});

it('keeps worktree preparation responsible for seeding the active loop packet', function (): void {
    $prepareWorktree = file_get_contents(repo_path('bin/orbit-prepare-worktree')) ?: '';
    $fastPath = file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '';
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';

    expect($prepareWorktree)
        ->toContain('seed_loop_packet')
        ->toContain('LOOP.md.example')
        ->toContain('.orbit/loop.md')
        ->toContain('skip .orbit/loop.md seed: target exists');

    expect($fastPath)
        ->toContain('`bin/orbit-prepare-worktree`; it seeds `.orbit/loop.md` when missing')
        ->toContain('Fill Goal and Scope in the seeded `.orbit/loop.md`');

    expect($harness)
        ->toContain('`bin/orbit-prepare-worktree`')
        ->toContain('It records only Goal, Scope, Proof, Status')
        ->toContain('Default `--base=main` requires local `main` to equal `origin/main`');

    expect($implementingFeatures)
        ->toContain('It seeds')
        ->toContain('`.orbit/loop.md` when it is missing')
        ->toContain('Fill or update the seeded `.orbit/loop.md` Goal and Scope')
        ->toContain('do not recreate the setup flow manually');
});

it('defines slice framing artifacts and skills', function (): void {
    $slice = file_get_contents(repo_path('SLICE.md.example')) ?: '';
    $loop = file_get_contents(repo_path('LOOP.md.example')) ?: '';
    $slicesBlock = "## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-example.md` | ready | none |\n\n## Proof";

    expect(repo_path('SLICE.md.example'))
        ->toBeFile()
        ->and($slice)
        ->toBe(
            "# Orbit Feature Slice\n\n- Slice: 01-example\n- Depends on: none\n\n## Outcome\n\n<one observable vertical increment>\n\n## Scope\n\n- Included:\n- Excluded:\n\n## Authority\n\n- Decisions:\n- Product docs:\n\n## Proof\n\n- Focused:\n",
        )
        ->and($loop)
        ->toContain($slicesBlock)
        ->not->toContain('| Slice | Depends on | Status |');

    foreach (['to-spec', 'to-tickets'] as $skill) {
        expect(repo_path(".agents/skills/{$skill}/SKILL.md"))
            ->toBeFile()
            ->and(repo_path(".agents/skills/{$skill}/agents/openai.yaml"))
            ->toBeFile();
    }

    $toSpec = file_get_contents(repo_path('.agents/skills/to-spec/SKILL.md')) ?: '';
    $toTickets = file_get_contents(repo_path('.agents/skills/to-tickets/SKILL.md')) ?: '';
    $intake = file_get_contents(repo_path('.agents/skills/handling-feature-requests/SKILL.md')) ?: '';
    expect($intake)
        ->toContain('https://github.com/mattpocock/skills/blob/main/skills/engineering/grill-with-docs/SKILL.md')
        ->toContain('under MIT')
        ->toContain('https://github.com/mattpocock/skills/blob/main/LICENSE');
    foreach ([$toSpec, $toTickets] as $skill) {
        expect($skill)
            ->toContain('under MIT')
            ->toContain('https://github.com/mattpocock/skills/blob/main/LICENSE')
            ->toContain('Do not use an external tracker')
            ->toContain('Do not install an upstream bundle')
            ->not->toContain('skills add')
            ->not->toContain('skill-installer')
            ->not->toContain('git clone')
            ->not->toContain('npx');
    }
    expect($toSpec)
        ->toContain('name: to-spec')
        ->toContain('description: Use when a settled Orbit feature frame needs serialization into the active loop.')
        ->toContain('.orbit/loop.md')
        ->toContain('settled Goal, Scope, authority, constraints')
        ->toContain('proof focus')
        ->toContain('this skill owns only that artifact');
    expect($toTickets)
        ->toContain('name: to-tickets')
        ->toContain('description: Use when a settled Orbit frame needs dependency-ordered vertical slice packets.')
        ->toContain('.orbit/slices')
        ->toContain('dependency-free slices')
        ->toContain('`ready`; dependent slices')
        ->toContain('`pending`')
        ->toContain('only the loop Slices table')
        ->toContain('owns slice packets and only that loop table');
    expect($toSpec.$toTickets)
        ->toContain('https://github.com/mattpocock/skills/blob/main/skills/engineering/grill-with-docs/SKILL.md')
        ->toContain('https://github.com/mattpocock/skills/blob/main/skills/engineering/to-spec/SKILL.md')
        ->toContain('https://github.com/mattpocock/skills/blob/main/skills/engineering/to-tickets/SKILL.md');
});

it('requires dependency-aware native worker framing in the current product decision', function (): void {
    $decisions = file_get_contents(repo_path('PRODUCT_DECISIONS.md')) ?: '';
    expect($decisions)
        ->toContain('2026-08-23')
        ->toContain('mandatory dependency-aware vertical slices')
        ->toContain('fresh native `gpt-5.6-luna` low worker per slice')
        ->toContain('(source: spec 2026-08-23-codex-native-luna-implementation-experiment.md)');
});

it('does not keep gateway-local generated agent artifacts', function (): void {
    $gatewayRoot = repo_path('apps/gateway');

    expect("{$gatewayRoot}/AGENTS.md")
        ->not->toBeFile()->and("{$gatewayRoot}/CLAUDE.md")
        ->not->toBeFile()->and("{$gatewayRoot}/.mcp.json")
        ->not->toBeFile()->and("{$gatewayRoot}/.codex")
        ->not->toBeDirectory()->and("{$gatewayRoot}/.agents")
        ->not->toBeDirectory()->and("{$gatewayRoot}/app/Providers/OrbitBoostServiceProvider.php")
        ->not->toBeFile();
});

it('uses one general reviewer and no standing specialist reviewers in the active loop', function (): void {
    $reviewer = file_get_contents(repo_path('.agents/review-personas/general.md')) ?: '';
    $active = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents(repo_path($path)),
        [
            'HARNESS.md',
            'AGENTS.md',
            'AGENT_FAST_PATH.md',
            '.agents/skills/implementing-features/SKILL.md',
        ],
    ));

    expect(repo_path('.agents/review-personas/general.md'))
        ->toBeFile()
        ->and($reviewer)
        ->toContain('CHECKOUT_PROOF')
        ->toContain('BLAST_RADIUS: not-required|complete|gaps')
        ->toContain('Never return PASS with BLAST_RADIUS: gaps')
        ->toContain('product decision, ownership boundary, transport, shared vocabulary, or shared schema')
        ->toContain('repository-wide search, inventory, or lintable check')
        ->toContain('HUMAN_JUDGMENT: required|not-required')
        ->toContain('VERDICT: PASS|FIX|ESCALATE')
        ->toContain('one concrete high-risk question')
        ->and($active)
        ->toContain('.agents/review-personas/general.md')
        ->toContain('Blast radius')
        ->toContain('same general reviewer')
        ->not->toContain('.agents/review-personas/cli-command.md')
        ->not->toContain('.agents/review-personas/docs-librarian.md')
        ->not->toContain('.agents/review-personas/post-feature-analyzer.md')
        ->not->toContain('.agents/review-personas/tauri-agent.md');
});

it('requires direct final outcome proof for runtime claims before reviewer PASS', function (): void {
    $harness = preg_replace('/\s+/', ' ', file_get_contents(repo_path('HARNESS.md')) ?: '') ?: '';
    $skill = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '',
    ) ?: '';
    $reviewer = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/review-personas/general.md')) ?: '',
    ) ?: '';

    expect($harness)
        ->toContain('When the Goal claims runtime reachability or convergence')
        ->toContain('directly exercise the claimed final outcome')
        ->toContain('A failed, excluded, still-required, or deferred final hop')
        ->toContain('`Verification.runtime` cannot be recorded as `passed`')
        ->toContain('structured receipt')
        ->not->toContain('Reject unknown receipt keys')
        ->not->toContain('do not scan target/command/evidence values')->and($skill)->toContain(
            'When the Goal claims runtime reachability or convergence',
        )->toContain('directly exercise the claimed final outcome')->toContain(
            'A failed, excluded, still-required, or deferred final hop',
        )->toContain('`Verification.runtime` cannot be recorded as `passed`')->toContain(
            'structured runtime receipt',
        )->toContain('HARNESS.md')
        ->not->toContain('`result=passed`')->and($reviewer)->toContain(
            'When the Goal claims runtime reachability or convergence',
        )->toContain('directly exercises the claimed final outcome')->toContain(
            'A failed, excluded, still-required, or deferred final hop',
        )->toContain('`Verification.runtime` cannot be recorded as `passed`')->toContain(
            'structured runtime receipt',
        )->toContain('return `FIX`');
});

it('uses native Luna slices and one Claude feature review', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $skill = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $prompt = file_get_contents(repo_path('.agents/skills/implementing-features/agents/openai.yaml')) ?: '';
    $fastPath = file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '';
    $agents = file_get_contents(repo_path('AGENTS.md')) ?: '';
    expect($harness)->toContain(
        'FRAME -> repeated BUILD <-> SLICE PROVE -> CHECKPOINT -> FEATURE PROVE -> REVIEW -> ACCEPT -> LAND',
    );
    $active = preg_replace('/\s+/', ' ', implode(' ', [
        $harness,
        $skill,
        $prompt,
        $fastPath ?? '',
        $agents ?? '',
    ])) ?: '';
    expect($active)
        ->toContain('gpt-5.6-luna')
        ->toContain('Sol')
        ->toContain('FEATURE PROVE')
        ->toContain('Claude general reviewer')
        ->not->toContain('Dispatch substantive repository edits to Grok workers')
        ->not->toContain('grok --yolo')
        ->not->toContain('Claude Opus');
});

it('models the complete native slice workflow graph', function (): void {
    $html = file_get_contents(repo_path('docs/orbit-feature-development-graph.html')) ?: '';
    preg_match('~<script type="application/json" id="orbit-feature-graph-data">(.*?)</script>~s', $html, $matches);
    $graph = json_decode(json: trim($matches[1] ?? ''), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    $featureStates = array_values(array_map(
        static fn (array $state): string => $state['id'],
        array_filter($graph['states'], static fn (array $state): bool => ($state['loop'] ?? null) === 'feature'),
    ));
    expect($graph['version'])
        ->toBe('5.0.0')
        ->and($featureStates)
        ->toBe([
            'request',
            'frame',
            'build',
            'slice-prove',
            'checkpoint',
            'feature-prove',
            'review',
            'accept',
            'land',
            'done',
        ])
        ->and(array_keys($graph['phases']))
        ->toBe(['frame', 'build', 'slice-prove', 'checkpoint', 'feature-prove', 'review', 'accept', 'land', 'release']);

    $featureTransitions = collect($graph['transitions'])->where('loop', 'feature')->keyBy('id');
    expect($featureTransitions->keys()->all())
        ->toBe([
            't-request-frame',
            't-frame-build',
            't-build-slice-prove',
            't-slice-prove-checkpoint',
            't-checkpoint-build',
            't-checkpoint-feature-prove',
            't-feature-prove-review',
            't-review-accept',
            't-review-fix-build',
            't-accept-land',
            't-land-done',
        ])
        ->and($featureTransitions['t-build-slice-prove']['from'].'>'.$featureTransitions['t-build-slice-prove']['to'])
        ->toBe('build>slice-prove')
        ->and($featureTransitions['t-checkpoint-build']['guard'])
        ->toBe('ready-slices-remain')
        ->and($featureTransitions['t-checkpoint-feature-prove']['guard'])
        ->toBe('all-indexed-slices-complete')
        ->and($featureTransitions['t-feature-prove-review']['to'])
        ->toBe('review')
        ->and($featureTransitions['t-review-accept']['to'])
        ->toBe('accept')
        ->and($featureTransitions['t-review-fix-build']['guard'])
        ->toBe('review-fix-earliest-slice-reset-later')
        ->and($featureTransitions['t-accept-land']['to'])
        ->toBe('land')
        ->and($featureTransitions['t-land-done']['to'])
        ->toBe('done');

    expect(collect($graph['actors']['harness_generic'])->pluck('id')->all())
        ->toBe(['sol-owner', 'luna-slice-worker', 'claude-general-reviewer', 'user', 'harness']);
});

it('keeps 30 effective skill descriptions on the native contract', function (): void {
    $skills = (array) glob(repo_path('.agents/skills/*/SKILL.md'));
    $sliceSkills = array_filter(
        $skills,
        static fn (string $path): bool => str_contains($path, '/to-spec/') || str_contains($path, '/to-tickets/'),
    );
    expect($skills)->toHaveCount(32)->and($sliceSkills)->toHaveCount(2);
    $descriptions = '';
    foreach (array_diff($skills, $sliceSkills) as $path) {
        $contents = (string) file_get_contents($path);
        preg_match('/^description:\s*(.+)$/m', $contents, $match);
        expect(trim($match[1] ?? ''))->not->toBeEmpty();
        $descriptions .= $match[1] ?? '';
    }
    expect($descriptions)->not->toContain('Grok worker')->not->toContain('Grok implement')->not->toContain('Opus-high');
});

it('keeps 11 effective agent metadata surfaces on the native contract', function (): void {
    $metadata = (array) glob(repo_path('.agents/skills/*/agents/openai.yaml'));
    $sliceMetadata = array_filter(
        $metadata,
        static fn (string $path): bool => str_contains($path, '/to-spec/') || str_contains($path, '/to-tickets/'),
    );
    expect($metadata)->toHaveCount(13)->and($sliceMetadata)->toHaveCount(2);
    $prompts = '';
    foreach (array_diff($metadata, $sliceMetadata) as $path) {
        $contents = (string) file_get_contents($path);
        expect($contents)->toContain('display_name:')->toContain('short_description:')->toContain('default_prompt:');
        $prompts .= $contents;
    }
    expect($prompts)->not->toContain('Grok worker')->not->toContain('Opus-high');
    expect(repo_path('.agents/skills/to-spec/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('.agents/skills/to-tickets/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('.agents/skills/to-spec/agents/openai.yaml'))
        ->toBeFile()
        ->and(repo_path('.agents/skills/to-tickets/agents/openai.yaml'))
        ->toBeFile();
});

it('keeps specialist personas non-active and dormant Grok tooling intact', function (): void {
    foreach (['cli-command', 'docs-librarian', 'tauri-agent'] as $persona) {
        $contents = (string) file_get_contents(repo_path(".agents/review-personas/{$persona}.md"));
        expect($contents)->toContain('Checklist Helper (non-active)')->toContain('Never spawn this persona');
    }
    expect((string) file_get_contents(repo_path('.agents/review-personas/general.md')))
        ->toContain('one completed candidate');
    expect(strtolower((string) file_get_contents(repo_path('bin/orbit-worker-spawn'))))->toContain('grok');
    expect(strtolower((string) file_get_contents(repo_path(
        'apps/gateway/tests/Feature/E2ESupport/WorkerToolsTest.php',
    ))))
        ->toContain('grok');
    expect((string) file_get_contents(repo_path('PRODUCT_DECISIONS.md')))->toContain('global coder role');
});

it('keeps intake compact and e2e prompts execution-safe', function (): void {
    $intake = file_get_contents(repo_path('.agents/skills/handling-feature-requests/SKILL.md')) ?: '';
    $intakePrompt = file_get_contents(repo_path('.agents/skills/handling-feature-requests/agents/openai.yaml')) ?: '';
    $e2ePrompt = file_get_contents(repo_path('.agents/skills/e2e-verification-lanes/agents/openai.yaml')) ?: '';

    expect($intake)
        ->toContain('Outcome')
        ->toContain('Acceptance')
        ->toContain('Constraints')
        ->toContain('Ambiguity')
        ->not->toContain('spawn implementation agents')->and($intakePrompt)->toContain(
            'stop when material ambiguity is none',
        )->and($e2ePrompt)->toContain(
            'Never run, delegate, split, background, schedule, hook, script, or trigger any composer test:e2e* command',
        )
        ->not->toContain('select Docker or Incus E2E verification commands');
});

it('keeps the native Orbit Agent development skill without a standing reviewer lane', function (): void {
    $skill = file_get_contents(repo_path('.agents/skills/tauri-agent-development/SKILL.md')) ?: '';
    $implementing = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';

    expect(repo_path('.agents/skills/tauri-agent-development/SKILL.md'))
        ->toBeFile()
        ->and($skill)
        ->toContain('name: tauri-agent-development')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('cargo test')
        ->toContain('Computer Use')
        ->and($implementing)
        ->toContain('macOS Agent: `tauri-agent-development`')
        ->not->toContain('.agents/review-personas/tauri-agent.md');
});

it('reserves human acceptance for judgment instead of deterministic checks', function (): void {
    $harness = preg_replace('/\s+/', ' ', file_get_contents(repo_path('HARNESS.md')) ?: '') ?: '';
    $skill = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '',
    ) ?: '';
    $reviewer = preg_replace('/\s+/', ' ', file_get_contents(repo_path('.agents/review-personas/general.md')) ?: '')
    ?: '';
    $implementingPrompt = file_get_contents(
        repo_path('.agents/skills/implementing-features/agents/openai.yaml'),
    ) ?: '';
    $e2e = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/e2e-verification-lanes/SKILL.md')) ?: '',
    ) ?: '';

    expect($harness)
        ->toContain('Never ask the user to execute a check the agent can execute')
        ->toContain('only a prepared surface that requires human judgment')
        ->toContain('repository tooling under `bin/`')
        ->toContain('no retained topology target')
        ->and($skill)
        ->toContain('Run every deterministic acceptance command yourself')
        ->toContain('Do not hand the user a mechanical command checklist')
        ->toContain('repository tooling under `bin/`')
        ->toContain('diff-routed `composer quality-check`')
        ->toContain('--actor=automated')
        ->toContain('Do not send an acceptance handoff when the actor is automated')
        ->toContain('keep it open for the user only when')
        ->and($reviewer)
        ->toContain('HUMAN_JUDGMENT: required|not-required')
        ->toContain('Executable files or a retained topology alone do not make a change human-observable')
        ->toContain('all remaining acceptance actions are deterministic commands')
        ->and($implementingPrompt)
        ->toContain('Complete the diff-derived proof venue')
        ->toContain('involve the user only for remaining human judgment')
        ->and($e2e)
        ->toContain('Do not ask the user to run E2E for ordinary feature completion')
        ->toContain('Only explain a manual E2E command after the user explicitly asks');
});

it('documents immutable feedback promotion and deterministic protection first', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $skill = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $ux = file_get_contents(repo_path('apps/docs/content/ux/commands/README.md')) ?: '';
    $normalizedHarness = preg_replace('/\s+/', ' ', $harness) ?: '';
    $normalizedSkill = preg_replace('/\s+/', ' ', $skill) ?: '';

    expect($normalizedHarness)
        ->toContain('All non-secret user feedback is stored verbatim as immutable events')
        ->toContain('redacted in memory before the event is appended')
        ->toContain('Never ask the user for a waiver')
        ->toContain('cheap calibrated UX check')
        ->toContain('one rejected example and one accepted example')
        ->toContain('Do not create a semantic grader')
        ->toContain('`UNKNOWN` never passes')
        ->and($normalizedSkill)
        ->toContain('Feedback And Protections')
        ->toContain('Never solicit a waiver')
        ->not->toContain('cheap calibrated UX check')
        ->not->toContain('Dogfood the concrete rejected and accepted pair first')->and($ux)->toContain(
            'Running -> Queued',
        )->toContain('bin/quality-check-progress-frame-check');
});

it('binds data list vocabulary to Laravel Prompts datatable across docs and review', function (): void {
    $dataList = file_get_contents(repo_path('apps/docs/content/ux/commands/lists/data-list.md')) ?: '';
    $lists = file_get_contents(repo_path('apps/docs/content/ux/commands/lists/README.md')) ?: '';
    $commandDesigner = file_get_contents(repo_path('.agents/skills/command-designer/SKILL.md')) ?: '';
    $cliReviewer = file_get_contents(repo_path('.agents/review-personas/cli-command.md')) ?: '';
    $generalReviewer = file_get_contents(repo_path('.agents/review-personas/general.md')) ?: '';
    $appListCommand = file_get_contents(repo_path('apps/cli/app/Commands/App/AppListCommand.php')) ?: '';
    $toolListCommand = file_get_contents(repo_path('apps/cli/app/Commands/Tool/ToolListCommand.php')) ?: '';
    $normalizedCliReviewer = preg_replace('/\s+/', ' ', $cliReviewer) ?: '';
    $normalizedGeneralReviewer = preg_replace('/\s+/', ' ', $generalReviewer) ?: '';

    expect($dataList)
        ->toContain('Laravel\\Prompts\\datatable')
        ->toContain('Name')
        ->toContain('Repository')
        ->toContain('Instances')
        ->toContain('Workspaces')
        ->toContain('App\\Support\\Prompts\\DataList')
        ->and($lists)
        ->toContain('data list')
        ->toContain('Laravel\\Prompts\\datatable')
        ->and($commandDesigner)
        ->toContain('data list means `Laravel\\Prompts\\datatable`')
        ->and($normalizedCliReviewer)
        ->toContain('data list means `Laravel\\Prompts\\datatable`')
        ->toContain('verify the concrete implementation symbol')
        ->toContain('raw user-provided column names')
        ->and($normalizedGeneralReviewer)
        ->toContain('verify the concrete implementation symbol')
        ->toContain('raw user-provided column names')
        ->and($appListCommand)
        ->toContain('use function Laravel\\Prompts\\datatable;')
        ->not
        ->toContain('App\\Support\\Prompts\\DataList')
        ->and($toolListCommand)
        ->toContain('App\\Support\\Prompts\\PropertyList')
        ->and(is_file(repo_path('apps/cli/app/Support/Prompts/DataList.php')))
        ->toBeFalse();
});

it('keeps loop improvement trigger-only and bounded', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $loopReview = file_get_contents(repo_path('.agents/skills/loop-review/SKILL.md')) ?: '';
    $normalizedHarness = preg_replace('/\s+/', ' ', $harness) ?: '';
    $normalizedLoopReview = preg_replace('/\s+/', ' ', $loopReview) ?: '';

    expect($normalizedHarness)
        ->toContain('Clean loops create no experiment')
        ->toContain('one active loop experiment')
        ->toContain('one target metric')
        ->toContain('prevention metric counts escaped same-surface defects after terminal PASS')
        ->toContain('not internal commit count or autonomous pre-land rework')
        ->toContain('Revert by default')
        ->not->toContain('## Metrics')
        ->not->toContain('bin/orbit-loop-metrics')->and($normalizedLoopReview)->toContain(
            'failed promoted protection',
        )->toContain('reviewer-confirmed recurring process failure')->toContain('existing compact receipts')->toContain(
            'Do not create generic evaluator tooling',
        )->toContain('escaped same-surface defect after terminal PASS')->toContain(
            'Internal commit count and autonomous pre-land rework are not prevention failures',
        );
});

it('keeps the general Orbit skill aligned with the macOS app boundary and managed Agent intent', function (): void {
    $skill = file_get_contents(repo_path('.agents/skills/orbit/SKILL.md')) ?: '';
    $concepts = file_get_contents(repo_path('.agents/skills/orbit/references/concepts.md')) ?: '';

    expect($skill)
        ->toContain('The macOS tray UI lives under `apps/macos`')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('.agents/skills/tauri-agent-development/SKILL.md')
        ->toContain('product surface does not install')
        ->toContain('restart, or uninstall the')
        ->toContain('the `agent` role creates managed Agent intent')
        ->not
        ->toContain('--orbit-agent-capable')
        ->and($concepts)
        ->toContain('Orbit Agent lane')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('Tauri tray UI')
        ->toContain('The `agent` workload role owns autonomous agent tools')
        ->toContain('Active workload roles provide the same intent.');
});

it('provides first-party boost skill sources in orbit packages', function (): void {
    expect(repo_path('packages/core/resources/boost/skills/orbit-core-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('packages/sdk/resources/boost/skills/orbit-sdk-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('apps/gateway/.ai/skills/orbit-cli-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('apps/gateway/.ai/skills/orbit-gateway-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('apps/gateway/.ai/skills/orbit-docs-development/SKILL.md'))
        ->toBeFile();
});

it('keeps first-party boost skill descriptions routed by app and package boundary', function (): void {
    $skills = [
        'apps/gateway/.ai/skills/orbit-cli-development/SKILL.md' => [
            'description: Use when working in apps/cli',
            'Laravel Zero',
            'JSON envelopes',
            'prompts',
            'executor',
        ],
        'apps/gateway/.ai/skills/orbit-gateway-development/SKILL.md' => [
            'description: Use when working in apps/gateway',
            'gateway HTTP/API',
            'provisioning',
            'database.sqlite',
            'bin/orbit-gateway-pest',
        ],
        'apps/gateway/.ai/skills/orbit-docs-development/SKILL.md' => [
            'description: Use when working in apps/docs',
            'apps/docs/content',
            'Librarian',
            'docs-lint',
            'command catalog',
        ],
        'packages/core/resources/boost/skills/orbit-core-development/SKILL.md' => [
            'description: Use when working in packages/core',
            'DTOs',
            'progress streaming',
            'HTTP envelopes',
            'cross-application primitives',
        ],
        'packages/sdk/resources/boost/skills/orbit-sdk-development/SKILL.md' => [
            'description: Use when working in packages/sdk',
            'gateway API request objects',
            'Saloon connectors',
            'Laravel SDK bindings',
            'client contract drift',
        ],
    ];

    foreach ($skills as $skillPath => $needles) {
        $skill = file_get_contents(repo_path($skillPath)) ?: '';

        foreach ($needles as $needle) {
            expect($skill)->toContain($needle);
        }
    }
});

it('keeps the app-owned orbit skill aligned with current CLI stream-json guidance and signatures', function (): void {
    $skill = (string) file_get_contents(repo_path('.agents/skills/orbit/SKILL.md'));
    $concepts = (string) file_get_contents(repo_path('.agents/skills/orbit/references/concepts.md'));
    $app = (string) file_get_contents(repo_path('.agents/skills/orbit/references/app.md'));
    $node = (string) file_get_contents(repo_path('.agents/skills/orbit/references/node.md'));
    $skillRef = (string) file_get_contents(repo_path('.agents/skills/orbit/references/skill.md'));
    $tool = (string) file_get_contents(repo_path('.agents/skills/orbit/references/tool.md'));
    $operation = (string) file_get_contents(repo_path('.agents/skills/orbit/references/operation.md'));

    preg_match('/## Universal output rules\n\n([\s\S]*?)\n\n## /', $skill, $universalOutputRules);

    expect($universalOutputRules[1] ?? '')
        ->toContain('prefer `--stream-json`')
        ->toContain('final-only `--json`');

    preg_match(
        '/agent-facing stream JSON commands include ([^.]+)\./',
        $concepts,
        $streamJsonCommands,
    );

    expect($streamJsonCommands[1] ?? '')
        ->toContain('`instance:setup`')
        ->toContain('`doctor`')
        ->toContain('`app:new`')
        ->toContain('`workspace:setup`')
        ->toContain('gateway-streamed `node:new`')
        ->toContain('`deploy:run`')
        ->toContain('`tool:install`')
        ->toContain('`s3:publish`')
        ->toContain('`update:all`')
        ->not->toContain('`update`');

    expect($app)
        ->toContain('--runtime-proxy-transport')
        ->toContain('orbit instance:setup <app.instance> [--json|--stream-json]')
        ->not->toContain('orbit instance:setup <app.instance> [--force]')->toContain(
            '--command=<command>',
        )->toContain(
            '--before=',
        )->toContain('--after=')
        ->not->toContain('--title=<title>')
        ->not->toContain('--order=<n>');

    expect($node)
        ->toContain('--postgres-node')
        ->toContain('--clickhouse-node')
        ->toContain('--host-key-fingerprint')
        ->toContain('--agent-tool')
        ->toContain('--operator-tld')
        ->toContain('--managed|--no-managed')
        ->not->toContain('node role:add <node> <role> [--tld');

    expect($tool)
        ->toContain('[--with-process] [--no-process]')
        ->toContain('--json|--stream-json')
        ->toContain('orbit tool:reload caddy')
        ->toContain('orbit tool:logs <tool> [--instance=<name>]');

    expect($skill)
        ->toContain('`orbit skill:install [provider] [path]`')
        ->toContain('references/skill.md');

    expect($skillRef)
        ->toContain('orbit skill:install [provider] [path] [--force] [--json]')
        ->toContain('~/.agents/skills/orbit')
        ->toContain('~/.claude/skills/orbit')
        ->toContain('~/.gemini/config/skills/orbit')
        ->toContain('~/.grok/skills/orbit')
        ->toContain('does not call the gateway');

    preg_match('/### Apps and instances[\s\S]*?### Workspaces/', $skill, $projectCommandIndex);

    expect($projectCommandIndex[0] ?? '')
        ->toContain('`orbit app:new [app]`')
        ->toContain('`orbit instance:setup [app.instance]`')
        ->toContain('`orbit instance-setup-step:add\|list\|remove`');

    expect($operation)
        ->toContain('--key=<key>')
        ->toContain('--dry-run')
        ->toContain('orbit profile [<url>]')
        ->toContain('never calls the gateway')
        ->not->toContain('orbit profile [<target>]')
        ->not->toContain('profile [<url>] [--app=');
});

it('routes ordinary work proportionally without unconditional full-harness preload', function (): void {
    $agents = preg_replace('/\s+/', ' ', file_get_contents(repo_path('AGENTS.md')) ?: '') ?: '';
    $fastPath = preg_replace('/\s+/', ' ', file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '') ?: '';
    $skill = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '',
    ) ?: '';

    expect($agents)
        ->toContain('Route new work with [`AGENT_FAST_PATH.md`](AGENT_FAST_PATH.md)')
        ->toContain('load `HARNESS.md` sections when the chosen lane reaches them')
        ->not->toContain('After this file, read')
        ->not->toContain('check whether a corresponding test exists')->and($fastPath)->toContain(
            'route proportionally',
        )->and($skill)
        ->not->toContain('Read `AGENTS.md`, `AGENT_FAST_PATH.md`, and `HARNESS.md`');
});

it('names the retained-incus acceptance venue on the CLI fast-path lane', function (): void {
    $fastPath = file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '';

    expect($fastPath)
        ->toContain('retained-incus')
        ->toContain('command-designer');
});

it('keeps HARNESS canonical with a compact pointer-based implementing skill', function (): void {
    $skillPath = repo_path('.agents/skills/implementing-features/SKILL.md');
    $skill = file_get_contents($skillPath) ?: '';
    $agents = file_get_contents(repo_path('AGENTS.md')) ?: '';
    $orbitAuthoredAgents = explode('<laravel-boost-guidelines>', $agents)[0];
    $harnessBytes = strlen(file_get_contents(repo_path('HARNESS.md')) ?: '');
    $fastPathBytes = strlen(file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '');

    expect(strlen($skill))
        ->toBeLessThanOrEqual(6720)
        ->and(strlen($orbitAuthoredAgents))
        ->toBeLessThanOrEqual(6144)
        ->and(strlen($skill) + strlen($orbitAuthoredAgents) + $harnessBytes + $fastPathBytes)
        ->toBeLessThanOrEqual(35600)
        ->and($skill)
        ->toContain('## FRAME')
        ->toContain('## BUILD')
        ->toContain('## SLICE PROVE / CHECKPOINT')
        ->toContain('## FEATURE PROVE / REVIEW')
        ->toContain('## ACCEPT')
        ->toContain('## LAND')
        ->toContain('bin/orbit-feature-acceptance route')
        ->toContain('bin/orbit-feature-acceptance ready')
        ->toContain('bin/orbit-feature-acceptance accept')
        ->toContain('bin/orbit-feature-land')
        ->toContain('bin/orbit-feature-finalization-check')
        ->toContain('bin/orbit-session-archive')
        ->toContain('bin/orbit-feature-feedback')
        ->toContain('Sol runs the feature proof and terminal gate after slice checkpoints')
        ->toContain('bin/orbit-feature-proof-receipt')
        ->toContain('handles corrections and amends its single checkpoint')
        ->toContain('focused Mago')
        ->toContain('predicate, identity, vocabulary, or schema')
        ->toContain('HARNESS.md')
        ->not->toContain('primitive=<exact requested primitive>')
        ->not->toContain('repository-wide search, inventory, or lintable check');
});

it('keeps FRAME inventory and focused Mago conditional on the change kind', function (): void {
    $harness = preg_replace('/\s+/', ' ', file_get_contents(repo_path('HARNESS.md')) ?: '') ?: '';
    $skill = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '',
    ) ?: '';

    expect($harness)
        ->toContain('predicate, identity, vocabulary, or schema')
        ->toContain('bounded producers, consumers, and dangerous invariants')
        ->toContain('ordinary local changes')
        ->toContain('focused Mago')
        ->and($skill)
        ->toContain('predicate, identity, vocabulary, or schema')
        ->toContain('ordinary local changes')
        ->toContain('focused Mago')
        ->toContain('accept --loop=.orbit/loop.md --actor=automated');
});

it('tombstones the post-feature analyzer persona as historical evidence', function (): void {
    $persona = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/review-personas/post-feature-analyzer.md')) ?: '',
    ) ?: '';

    expect($persona)
        ->toContain('retired historical')
        ->toContain('Do not spawn it during ordinary feature delivery')
        ->toContain('current workflow authority is `HARNESS.md`')
        ->toContain('clean loops create no analyzer lane');
});

it('keeps reviewer FIX and same-candidate proof retry distinct transitions', function (): void {
    $harness = preg_replace('/\s+/', ' ', file_get_contents(repo_path('HARNESS.md')) ?: '') ?: '';

    expect($harness)
        ->toContain('A same-candidate proof retry is not a reviewer FIX')
        ->toContain('preserve a still-valid Review and Reviewed feature tip')
        ->toContain('reset `Reviewed feature tip: none`');
});

it('requires the native Luna instruction and metadata contract', function (): void {
    $sources = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents(repo_path($path)),
        ['AGENTS.md', 'AGENT_FAST_PATH.md', 'HARNESS.md', '.agents/skills/implementing-features/SKILL.md'],
    ));
    $decision = (string) file_get_contents(repo_path('PRODUCT_DECISIONS.md'));

    expect($sources)
        ->toContain(
            'FRAME -> repeated BUILD <-> SLICE PROVE -> CHECKPOINT -> FEATURE PROVE -> REVIEW -> ACCEPT -> LAND',
        )
        ->toContain('mandatory dependency-aware vertical slices')
        ->toContain('reasoning_effort=low')
        ->not->toContain('Missing Claude review tooling is a blocker')->toContain('REVIEW blocker')->toContain(
            'one fresh native Codex child using model `gpt-5.6-luna` and `reasoning_effort=low`',
        )->toContain('never edits packets or `.orbit`')->toContain(
            'resets that slice and every later indexed slice to pending/none as dependencies require',
        )->toContain(
            'reset that slice and every later indexed slice to pending/none as dependencies require, then set the earliest slice ready and building',
        )
        ->not->toContain('Dispatch substantive repository edits to Grok workers')
        ->not->toContain('grok --yolo');
    expect($decision)
        ->toContain('2026-08-24')
        ->toContain('FRAME -> repeated BUILD <-> SLICE PROVE')
        ->toContain('same Luna child handles corrections and amends its checkpoint')
        ->toContain('fresh child handles each next or reopened slice');
    $brief = (string) file_get_contents(repo_path('.agents/skills/implementing-features/brief-template.md'));
    $yaml = (string) file_get_contents(repo_path('.agents/skills/implementing-features/agents/openai.yaml'));
    $general = (string) file_get_contents(repo_path('.agents/review-personas/general.md'));
    expect($brief)
        ->toContain('Sol-owned artifacts (read-only):')
        ->toContain('## Dangerous invariants')
        ->toContain('Gate receipt commit equals current HEAD and receipt dirty is false.')
        ->toContain('- <named invariant>');
    expect($yaml)->toContain('reasoning_effort=low')->toContain('. Complete the diff-derived');
    expect($general)->toContain('diff-first Claude general review')->not->toContain('provider-neutral review');
});

it('models the native Luna workflow graph', function (): void {
    $graph = (string) file_get_contents(repo_path('docs/orbit-feature-development-graph.html'));
    preg_match('~<script type="application/json" id="orbit-feature-graph-data">(.*?)</script>~s', $graph, $matches);
    $data = json_decode(json: trim($matches[1] ?? ''), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    expect($data['version'])->toBe('5.0.0')->and($data['scope'])->toBe('feature-development-loop');

    $slice = $data['phases']['slice-prove'];
    expect(array_keys($slice))
        ->not->toContain('prove_sequence')
        ->not->toContain('runtime')
        ->not->toContain('live_environment_proof')
        ->not->toContain('protection_ladder');
    expect($slice['enforcement'])
        ->toBe('already_enforced')
        ->and($slice['owns'])
        ->toBe(['slice-red-green-focused-proof'])
        ->and($slice['inputs'])
        ->toBe([
            'Indexed slice packet and scope',
            'Owned source and focused test targets',
            'Fresh native Luna child',
        ])
        ->and($slice['happens'])
        ->toBe([
            'Capture focused RED before implementation',
            'Implement the smallest slice correction',
            'Run focused GREEN proof',
        ])
        ->and($slice['exit'])
        ->toBe(['Focused proof passed → CHECKPOINT'])
        ->and($slice['mini_flow'])
        ->toBe(['RED → implementation → GREEN → CHECKPOINT']);

    $sliceJson = json_encode(value: $slice, flags: JSON_THROW_ON_ERROR);
    expect($sliceJson)
        ->not->toContain('reviewer')
        ->not->toContain('runtime')
        ->not->toContain('live')
        ->not->toContain('protection')
        ->not->toContain('ACCEPT')
        ->not->toContain('feature-level')
        ->not->toContain('venue')
        ->not->toContain('broader-gate')
        ->not->toContain('clean-candidate');
});

it('models feature proof before one Claude review', function (): void {
    $graph = (string) file_get_contents(repo_path('docs/orbit-feature-development-graph.html'));
    preg_match('~<script type="application/json" id="orbit-feature-graph-data">(.*?)</script>~s', $graph, $matches);
    $data = json_decode(json: trim($matches[1]), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect($data['version'])
        ->toBe('5.0.0')
        ->and($data['scope'])
        ->toBe('feature-development-loop')
        ->and($data['model_status'])
        ->toBe('current-contract');

    $featureProof = $data['phases']['feature-prove'];
    expect($featureProof['owns'])
        ->toBe([
            'feature-level proof receipt',
            'runtime evidence',
            'derived venue proof',
            'protection proof',
        ])
        ->and($featureProof['exit'])
        ->toBe(['Proof and receipt complete → REVIEW']);

    expect(array_keys($data['prove_sequence']))
        ->not->toContain('proposed_target')
        ->not->toContain('historical_note')
        ->not->toContain('retired_note');
    expect(array_keys($data['live_environment_proof']))
        ->not->toContain('proposed_target')
        ->not->toContain('historical_note')
        ->not->toContain('retired_note');
    expect(array_column($data['prove_sequence']['current']['steps'], 'id'))->toBe([
        'focused-and-broader-checks',
        'exact-candidate-clean',
        'derived-venue-and-runtime-proof',
        'feature-proof-receipt',
        'ready-for-review',
    ]);

    expect($data['phases']['review']['happens'])
        ->toBe(['One Claude general reviewer via tmux'])
        ->and($data['phases']['review']['exit'])
        ->toBe(['PASS → ACCEPT']);
});

it('shows the current Luna lifecycle in the workflow graph', function (): void {
    $graph = (string) file_get_contents(repo_path('docs/orbit-feature-development-graph.html'));

    expect($graph)
        ->toContain('5.0.0')
        ->toContain('gpt-5.6-luna')
        ->toContain('earliest affected complete slice')
        ->toContain('FRAME → BUILD → SLICE PROVE → CHECKPOINT → FEATURE PROVE → REVIEW → ACCEPT → LAND')
        ->toContain('Luna RED/GREEN → Sol CHECKPOINT')
        ->toContain('remaining slices → BUILD')
        ->toContain('all complete → FEATURE PROVE')
        ->toContain('→ one Claude REVIEW')
        ->toContain('FEATURE PROVE: focused checks, then diff-routed broader gate')
        ->toContain('FEATURE PROVE: exact clean candidate')
        ->toContain('FEATURE PROVE: derived venue plus required runtime/live receipt')
        ->toContain('FEATURE PROVE: feature proof receipt')
        ->toContain('REVIEW: one Claude general reviewer on the exact HEAD');
});

it('removes stale implementation and proof ordering from the workflow graph', function (): void {
    $graph = (string) file_get_contents(repo_path('docs/orbit-feature-development-graph.html'));

    foreach ([
        'Grok worker via',
        'FRAME → BUILD ↔ PROVE → ACCEPT → LAND',
        'Proposed target: pre-live venue proof',
        'proposed-post-review-live-proof',
        'post-review-second-runtime-receipt',
        'Mixed current',
        'proposed/target',
        'current-vs-proposed',
        'PROVE sequence: current enforcement vs proposed target',
        'class="card proposed"',
        'Reviewer PASS on that exact HEAD',
        'Conditional live-environment proof with the <em>same</em> reviewed candidate',
        'Live proof (in PROVE)',
        'in PROVE:',
        'Release lifecycle / proposed ordering / future safety',
        'Solid boxes are enforced; dashed purple',
        'reviewer PASS (current order)',
        'Feature proof contract',
        'Visible as the agreed target',
        'Pre-live venue proof',
        'Candidate-bound evidence → ACCEPT',
        'retired_note',
        'proposed_target',
        'proposed_or_clarifying',
        'reviewer-pass-exact-head',
        'ready-for-accept',
    ] as $stale) {
        expect($graph)->not->toContain($stale);
    }
});

it('keeps the eight high-stakes constraints explicit on the implementing path', function (): void {
    $harness = preg_replace('/\s+/', ' ', file_get_contents(repo_path('HARNESS.md')) ?: '') ?: '';
    $agents = preg_replace('/\s+/', ' ', file_get_contents(repo_path('AGENTS.md')) ?: '') ?: '';
    $fastPath = preg_replace('/\s+/', ' ', file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '') ?: '';
    $skill = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '',
    ) ?: '';
    $implementingPath = implode(' ', [$harness, $agents, $fastPath, $skill]);

    expect($harness)
        ->toContain('never run, delegate, background, schedule, hook, script, or trigger')
        ->toContain('only when the user explicitly invokes the Composer command from a shell')
        ->toContain('Never ask the user to run them for ordinary feature completion')
        ->toContain('redacted in memory before the event is appended')
        ->toContain('After `FINALIZATION: PASS`, execute that exact command separately')
        ->toContain('If the feature tip moves, acceptance is invalid')
        ->toContain('needs explicit user acceptance before merge')
        ->toContain('Cleanup requires those archive and index bytes to be tracked and committed')
        ->and($agents)
        ->toContain('`composer test:e2e*` lanes are human-only')
        ->not
        ->toContain('Never ask the user to run them for ordinary feature completion')
        ->and($implementingPath)
        ->toContain('bin/orbit-prepare-worktree')
        ->toContain('stop and report the blocker')
        ->toContain('never discard user changes');
});
