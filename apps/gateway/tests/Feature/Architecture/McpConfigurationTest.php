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
        ->toContain('orbit-cli-development');
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

it('provides a compact Solo todo handoff skill for Claude Opus implementation agents', function (): void {
    $skillPath = repo_path('.agents/skills/solo-todo-handoff/SKILL.md');
    $skill = is_file($skillPath) ? (file_get_contents($skillPath) ?: '') : '';

    preg_match('/## Prompt Skeleton\n\n```text\n([\s\S]*?)\n```/', $skill, $promptSkeleton);
    $promptSkeletonBody = $promptSkeleton[1] ?? '';

    expect($skillPath)
        ->toBeFile()
        ->and($skill)
        ->toContain('name: solo-todo-handoff')
        ->toContain('Solo todo')
        ->toContain('todo_get')
        ->toContain('list_agent_tools')
        ->toContain('spawn_agent')
        ->toContain('send_input')
        ->toContain('--model')
        ->toContain('opus')
        ->toContain('--effort')
        ->toContain('medium')
        ->toContain('4000 characters')
        ->toContain('send-and-forget handoff')
        ->toContain('Do not poll, supervise, or')
        ->toContain('send follow-up prompts')
        ->toContain('.agents/skills/implementing-features/SKILL.md')
        ->not->toContain('corrective prompt')
        ->not->toContain('If Claude stops midway');

    expect($promptSkeletonBody)
        ->not
        ->toBe('')
        ->and(str_starts_with($promptSkeletonBody, '/goal '))
        ->toBeTrue()
        ->and(mb_strlen($promptSkeletonBody))
        ->toBeLessThanOrEqual(4000);
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
    $signals = file_get_contents(repo_path('HARNESS_SIGNALS.md')) ?: '';
    $loopTemplate = file_get_contents(repo_path('LOOP.md.example')) ?: '';
    $implementingFeatures = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $analyzer = file_get_contents(repo_path('.agents/review-personas/post-feature-analyzer.md')) ?: '';
    $retiredDistillation = file_get_contents(repo_path('.agents/review-personas/post-feature-distillation.md')) ?: '';

    expect(repo_path('.agents/review-personas/post-feature-analyzer.md'))
        ->toBeFile()
        ->and($analyzer)
        ->toContain('Codex/Solo session messages')
        ->toContain('correct-noop')
        ->toContain('wrong-target')
        ->toContain('must not steer live work')
        ->and($harness)
        ->toContain('Normal feature work does not need an outer loop-improver watcher')
        ->toContain('.agents/review-personas/post-feature-analyzer.md')
        ->toContain('Post-feature analyzer')
        ->not
        ->toContain('Post-feature distillation | `.agents/review-personas/post-feature-distillation.md`')
        ->and($signals)
        ->toContain('post-feature analyzer reviews the completed loop')
        ->and($loopTemplate)
        ->toContain('- Fresh analyzer:')
        ->toContain('correct-noop | missed | redundant | wrong-target')
        ->and($implementingFeatures)
        ->toContain('Post-Feature Analyzer Delegation')
        ->toContain('.agents/review-personas/post-feature-analyzer.md')
        ->toContain('Codex thread id or transcript path')
        ->and($retiredDistillation)
        ->toContain('Retired: Post-Feature Distillation Reviewer')
        ->toContain('Do not use this file for new Orbit feature loops');
});

it('provides first-party boost skill sources in orbit packages', function (): void {
    expect(repo_path('packages/core/resources/boost/skills/orbit-core-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('packages/sdk/resources/boost/skills/orbit-sdk-development/SKILL.md'))
        ->toBeFile()
        ->and(repo_path('apps/gateway/.ai/skills/orbit-cli-development/SKILL.md'))
        ->toBeFile();
});

it('keeps the project-owned orbit skill aligned with current CLI stream-json guidance and signatures', function (): void {
    $skill = file_get_contents(repo_path('.agents/skills/orbit/SKILL.md')) ?: '';
    $concepts = file_get_contents(repo_path('.agents/skills/orbit/references/concepts.md')) ?: '';
    $app = file_get_contents(repo_path('.agents/skills/orbit/references/app.md')) ?: '';
    $node = file_get_contents(repo_path('.agents/skills/orbit/references/node.md')) ?: '';
    $tool = file_get_contents(repo_path('.agents/skills/orbit/references/tool.md')) ?: '';
    $operation = file_get_contents(repo_path('.agents/skills/orbit/references/operation.md')) ?: '';

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
        ->not->toContain('`update`')
        ->not->toContain('`update:all`');

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
