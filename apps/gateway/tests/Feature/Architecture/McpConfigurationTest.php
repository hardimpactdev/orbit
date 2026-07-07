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

it('keeps the project-owned orbit skill in the agents skill catalog', function (): void {
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
        ->toContain('Fill the seeded `.orbit/loop.md`');

    expect($harness)
        ->toContain('`bin/orbit-prepare-worktree` seeds `.orbit/loop.md`')
        ->toContain('The handoff owner or feature')
        ->toContain('orchestrator enriches that seeded packet');

    expect($implementingFeatures)
        ->toContain('seeds `.orbit/loop.md` when it is missing')
        ->toContain('Fill or update the seeded `.orbit/loop.md`')
        ->toContain('If `.orbit/loop.md` is missing, report a setup blocker before editing.')
        ->toContain('Do not create `.orbit/loop.md`')
        ->not->toContain('`.orbit/loop.md` when that file exists')
        ->not->toContain('then copy it to `.orbit/loop.md`');

    expect($todoHandoff)
        ->toContain('fill the seeded .orbit/loop.md Done Contract');
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

it('routes post-feature analysis through the analyzer persona', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $loopTemplate = file_get_contents(repo_path('LOOP.md.example')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $analyzer = file_get_contents(repo_path('.agents/review-personas/post-feature-analyzer.md')) ?: '';

    expect(repo_path('.agents/review-personas/post-feature-analyzer.md'))
        ->toBeFile()
        ->and(repo_path('.agents/review-personas/post-feature-distillation.md'))
        ->not->toBeFile()->and($analyzer)->toContain('read-only')->and($harness)->toContain(
            '.agents/review-personas/post-feature-analyzer.md',
        )
        ->not->toContain('post-feature-distillation')->and($loopTemplate)->toContain('correct-noop')->toContain(
            'wrong-target',
        )->and($implementingFeatures)->toContain('.agents/review-personas/post-feature-analyzer.md')
        ->not->toContain('post-feature-distillation');
});

it('keeps post-feature analyzers on demand for compact loops', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $loopTemplate = file_get_contents(repo_path('LOOP.md.example')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $normalizedHarness = preg_replace('/\s+/', ' ', $harness) ?: '';

    expect($harness)
        ->toContain('Default compact loops do not run a standing post-feature analyzer.')
        ->toContain('Run the fresh post-feature analyzer only when explicitly requested')
        ->toContain('`not used - <rationale>`')
        ->not->toContain('Fresh Solo Codex analyzer report for non-trivial loops');

    expect($normalizedHarness)
        ->toContain(
            'multi-slice, parallel workers, topology/live-node proof, product-contract change, release scope, messy human steering, reviewer dispute, suspected guardrail drift, or changes to analyzer/loop guardrails',
        );

    expect($loopTemplate)
        ->toContain('`not used - <rationale>`')
        ->toContain('normal compact-loop analyzer result');

    expect($implementingFeatures)
        ->toContain('record `not used - <rationale>` for compact loops')
        ->toContain('analyzer/loop guardrail changes')
        ->not->toContain('request fresh-context post-feature analysis when the loop was non-trivial');
});

it('keeps the Solo Codex analyzer spawn recipe canonical in the HARNESS role matrix', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $analyzer = file_get_contents(repo_path('.agents/review-personas/post-feature-analyzer.md')) ?: '';
    $cliReviewer = file_get_contents(repo_path('.agents/review-personas/cli-command.md')) ?: '';
    $docsLibrarian = file_get_contents(repo_path('.agents/review-personas/docs-librarian.md')) ?: '';

    expect($harness)
        ->toContain('## Solo Role Matrix')
        ->toContain('| Post-feature analyzer | Solo-managed Codex analyzer |')
        ->toContain('Post-feature analyzers use the enabled `Codex` tool through Solo');

    $roleMatrixStart = strpos($harness, '## Solo Role Matrix');
    $roleMatrixEnd = strpos($harness, "\n## ", $roleMatrixStart + 1);
    $roleMatrix = substr(
        $harness,
        $roleMatrixStart,
        $roleMatrixEnd === false ? null : $roleMatrixEnd - $roleMatrixStart,
    );

    expect($roleMatrix)
        ->toContain('extra_args')
        ->toContain('--cd')
        ->toContain('--cwd')
        ->and(substr_count($harness, 'extra_args'))
        ->toBe(substr_count($roleMatrix, 'extra_args'));

    foreach ([$analyzer, $cliReviewer, $docsLibrarian] as $persona) {
        expect($persona)
            ->toContain('Spawn per the Solo Role Matrix in HARNESS.md')
            ->not->toContain('extra_args');
    }
});

it('requires checkout proof and machine-parseable verdicts from Solo-spawned review personas', function (): void {
    $personaPaths = [
        '.agents/review-personas/cli-command.md',
        '.agents/review-personas/docs-librarian.md',
        '.agents/review-personas/post-feature-analyzer.md',
        '.agents/review-personas/tauri-agent.md',
    ];

    foreach ($personaPaths as $personaPath) {
        $persona = file_get_contents(repo_path($personaPath)) ?: '';

        expect($persona)
            ->toContain('REQUIRED PROOF')
            ->toContain('CHECKOUT_PROOF')
            ->toContain('git branch --show-current')
            ->toContain('git status --short --branch')
            ->toContain('VERDICT:');
    }

    $analyzer = file_get_contents(repo_path('.agents/review-personas/post-feature-analyzer.md')) ?: '';

    expect($analyzer)
        ->toContain('yes|flawed|blocked-by-missing-evidence')
        ->toContain('correct-noop')
        ->toContain('missed')
        ->toContain('redundant')
        ->toContain('wrong-target')
        ->toContain('defer');
});

it('routes Orbit Agent Tauri work through a dedicated skill and reviewer persona', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $skill = file_get_contents(repo_path('.agents/skills/tauri-agent-development/SKILL.md')) ?: '';
    $reviewer = file_get_contents(repo_path('.agents/review-personas/tauri-agent.md')) ?: '';

    expect(repo_path('.agents/skills/tauri-agent-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('.agents/review-personas/tauri-agent.md'))
        ->toBeFile()
        ->and($harness)
        ->toContain('| Orbit Agent Rust services |')
        ->toContain('.agents/skills/tauri-agent-development/SKILL.md')
        ->toContain('.agents/review-personas/tauri-agent.md')
        ->and($implementingFeatures)
        ->toContain('.agents/review-personas/tauri-agent.md')
        ->and($skill)
        ->toContain('name: tauri-agent-development')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('cargo test')
        ->toContain('Computer Use')
        ->and($reviewer)
        ->toContain('Spawn per the Solo Role Matrix in HARNESS.md')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('cargo test')
        ->toContain('cargo clippy --all-targets -- -D warnings')
        ->toContain('Computer Use')
        ->toContain('VERDICT:');
});

it('keeps the general Orbit skill aligned with the macOS Orbit Agent app boundary', function (): void {
    $skill = file_get_contents(repo_path('.agents/skills/orbit/SKILL.md')) ?: '';
    $concepts = file_get_contents(repo_path('.agents/skills/orbit/references/concepts.md')) ?: '';

    expect($skill)
        ->toContain('The macOS tray UI lives under `apps/macos`')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('.agents/skills/tauri-agent-development/SKILL.md')
        ->toContain('does not install, start, update, restart, or uninstall the macOS app')
        ->toContain('The `agent` workload role does not imply Orbit Agent capability')
        ->and($concepts)
        ->toContain('Orbit Agent lane')
        ->toContain('apps/agent')
        ->toContain('apps/macos')
        ->toContain('Tauri tray UI')
        ->toContain('The `agent` workload role is separate from Orbit Agent capability.');
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

it('keeps the project-owned orbit skill aligned with current CLI stream-json guidance and signatures', function (): void {
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
        ->toContain('`app:setup`')
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
        ->toContain('orbit app:setup [<app>] [--json|--stream-json]')
        ->not->toContain('orbit app:setup [<app>] [--force]')->toContain('--command=<command>')->toContain(
            '--before=',
        )->toContain('--after=')
        ->not->toContain('--title=<title>')
        ->not->toContain('--order=<n>');

    expect($node)
        ->toContain('--postgres-node')
        ->toContain('--clickhouse-node')
        ->toContain('--host-key-fingerprint')
        ->toContain('--agent-tool');

    expect($tool)
        ->toContain('[--with-process] [--no-process]')
        ->toContain('--json|--stream-json');

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

    preg_match('/### Apps[\s\S]*?### Workspaces/', $skill, $appCommandIndex);

    expect($appCommandIndex[0] ?? '')
        ->toContain('`orbit app:setup [app]`')
        ->toContain('`orbit app-setup-step:add`')
        ->toContain('`orbit app-setup-step:list`')
        ->toContain('`orbit app-setup-step:remove`');

    expect($operation)
        ->toContain('--key=<key>')
        ->toContain('--dry-run');
});
