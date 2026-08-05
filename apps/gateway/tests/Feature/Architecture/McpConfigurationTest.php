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

it('provides a compact Solo todo handoff skill for Codex implementation agents', function (): void {
    $skillPath = repo_path('.agents/skills/solo-todo-handoff/SKILL.md');
    $skill = is_file($skillPath) ? (file_get_contents($skillPath) ?: '') : '';

    preg_match('/## Prompt Skeleton\n\n```text\n([\s\S]*?)\n```/', $skill, $promptSkeleton);
    $promptSkeletonBody = $promptSkeleton[1] ?? '';

    expect($skillPath)
        ->toBeFile()
        ->and($skill)
        ->toContain('name: solo-todo-handoff')
        ->toContain('todo_get')
        ->toContain('list_agent_tools')
        ->toContain('spawn_agent')
        ->toContain('send_input')
        ->toContain('.agents/skills/implementing-features/SKILL.md');

    expect($promptSkeletonBody)
        ->not
        ->toBeEmpty()
        ->and(str_starts_with($promptSkeletonBody, '/goal '))
        ->toBeTrue()
        ->and(mb_strlen($promptSkeletonBody))
        ->toBeLessThanOrEqual(4000);
});

it('keeps worktree preparation responsible for seeding the active loop packet', function (): void {
    $prepareWorktree = file_get_contents(repo_path('bin/orbit-prepare-worktree')) ?: '';
    $fastPath = file_get_contents(repo_path('AGENT_FAST_PATH.md')) ?: '';
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $todoHandoff = file_get_contents(repo_path('.agents/skills/solo-todo-handoff/SKILL.md')) ?: '';

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
        ->toContain('It records only Goal, Scope, Proof, Status');

    expect($implementingFeatures)
        ->toContain('It seeds')
        ->toContain('`.orbit/loop.md` when it is missing')
        ->toContain('Fill or update the seeded `.orbit/loop.md` Goal and Scope')
        ->toContain('do not recreate the setup flow manually');

    expect($todoHandoff)
        ->toContain('fill the seeded .orbit/loop.md Goal and Scope');
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
        ->and($skill)
        ->toContain('When the Goal claims runtime reachability or convergence')
        ->toContain('directly exercise the claimed final outcome')
        ->toContain('A failed, excluded, still-required, or deferred final hop')
        ->toContain('`Verification.runtime` cannot be recorded as `passed`')
        ->toContain('structured runtime receipt')
        ->and($reviewer)
        ->toContain('When the Goal claims runtime reachability or convergence')
        ->toContain('directly exercises the claimed final outcome')
        ->toContain('A failed, excluded, still-required, or deferred final hop')
        ->toContain('`Verification.runtime` cannot be recorded as `passed`')
        ->toContain('structured runtime receipt')
        ->toContain('return `FIX`');
});

it('keeps the current feature owner in charge with optional bounded workers', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $skill = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';

    expect($harness)
        ->toContain('FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND')
        ->toContain('current feature owner owns FRAME through LAND')
        ->toContain('Workers are optional and bounded')
        ->and($skill)
        ->toContain('The current feature owner may implement directly')
        ->toContain('Use workers only when useful')
        ->not->toContain('It must spawn')
        ->not->toContain('substantive repository edits are forbidden');
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
            'leave implementation ownership and optional delegation to implementing-features',
        )
        ->not->toContain('delegate documentation and implementation through Solo')->and($e2ePrompt)->toContain(
            'Never run, delegate, split, background, schedule, hook, script, or trigger any composer test:e2e* command',
        )
        ->not->toContain('select Docker or Incus E2E verification commands')
        ->not->toContain('split provider work across Solo agents');
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
        ->toContain('Keep it open for the user only when')
        ->and($reviewer)
        ->toContain('HUMAN_JUDGMENT: required|not-required')
        ->toContain('Executable files or a retained topology alone do not make a change human-observable')
        ->toContain('all remaining acceptance actions are deterministic commands')
        ->and($implementingPrompt)
        ->toContain('complete the diff-derived proof venue')
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
        ->and($normalizedSkill)
        ->toContain('Dogfood the concrete rejected and accepted pair first')
        ->toContain('Prefer deterministic protection')
        ->toContain('Do not create a semantic grader')
        ->toContain('`UNKNOWN` never passes')
        ->and($ux)
        ->toContain('Running -> Queued')
        ->toContain('bin/quality-check-progress-frame-check');
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
        ->toContain('orbit tool:logs dns');

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
