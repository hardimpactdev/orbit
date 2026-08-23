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

it('keeps the orchestrating session in charge while tmux workers implement and Claude Opus reviews', function (): void {
    $harness = file_get_contents(repo_path('HARNESS.md')) ?: '';
    $skill = file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md')) ?: '';
    $prompt = file_get_contents(repo_path('.agents/skills/implementing-features/agents/openai.yaml')) ?: '';
    $intake = file_get_contents(repo_path('.agents/skills/handling-feature-requests/SKILL.md')) ?: '';
    $intakePrompt = file_get_contents(repo_path('.agents/skills/handling-feature-requests/agents/openai.yaml')) ?: '';
    $featureGraph = file_get_contents(repo_path('docs/orbit-feature-development-graph.html')) ?: '';
    $ownerSentence = 'The orchestrating session (Codex or Claude) that the human started is the sole feature owner.';
    $workersSentence = 'Workers run in the feature tmux session `feat-<slug>` created by `bin/orbit-prepare-worktree`.';
    $dispatchSentence = 'Dispatch substantive repository edits to Grok workers with `bin/orbit-worker-spawn --role=impl --cli=grok --brief=<path>`. Do not substitute an owner subagent or direct owner implementation.';
    $reviewerSentence = 'Spawn one independent Claude general reviewer for the review cycle with `bin/orbit-worker-spawn --role=review --cli=claude --brief=<path>`.';
    $missingToolsSentence = 'Missing tmux, grok, or claude on the machine is a blocker.';
    $watchSentence = 'Wait for workers with `bin/orbit-worker-watch`; read handoff files. Periodically study `bin/orbit-worker-capture <id>`. Observation is not intervention: elapsed time, no diff, or context collection is not a stall. Intervene on stale output, an exited pane, blocked/request status, a repeated failed action, visible loop or drift, or a concrete question.';
    $heartbeatSentence = 'Every brief requires `bin/orbit-worker-heartbeat <id> --status=<working|blocked> --note=<text>` at working or blocked updates, and `bin/orbit-worker-handoff <id> <file> [--note=<text>]` as the atomic terminal operation; workers never merge.';
    $implHandoffSentence = 'Impl handoff names `candidate=<40-character sha>` and a valid SHA-bound `bin/orbit-feature-proof-receipt`.';
    $rearmSentence = 'Re-arm `bin/orbit-worker-watch` after handling an event with `--ack=<snapshot>` or `--target=<id>`. `--ignore` remains as cheap compatibility.';
    $stopSentence = 'Stop finished workers with `bin/orbit-worker-stop <id>` (or `--all-finished`) before LAND; never kill windows or servers with raw tmux commands.';
    $proofWindowSentence = 'CLI retained topology proof runs in a user-attachable `proof-1` window of the feature tmux session; keep it open for the user only when `HUMAN_JUDGMENT: required`.';
    $ownershipSentence = 'Session ownership is exact: the loop `Session:` line equals `feat-<slug>` and the tmux session path equals the feature worktree; LAND refuses to run inside the feature session.';
    $cleanupSentence = "kill the feature tmux session (`tmux kill-session -t '=feat-<slug>'`, validated by `bin/orbit-feature-finalization-check`), remove the exact clean merged worktree, then delete the exact merged feature branch.";
    $acceptanceSentence = 'user acceptance reads the verbatim message from STDIN and requires its `codex://` or `claude://` source reference';
    $waiverSentence = 'a safe Codex or Claude source reference';

    $receiptOwnershipSentence = 'The implementer owns focused checks and the one terminal gate; owner';

    expect($harness)
        ->toContain('FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND')
        ->toContain($ownerSentence)
        ->toContain($workersSentence)
        ->toContain($dispatchSentence)
        ->toContain($reviewerSentence)
        ->toContain($missingToolsSentence)
        ->toContain($watchSentence)
        ->toContain($receiptOwnershipSentence)
        ->toContain($heartbeatSentence)
        ->toContain($implHandoffSentence)
        ->toContain($rearmSentence)
        ->toContain($stopSentence)
        ->toContain($proofWindowSentence)
        ->toContain($ownershipSentence)
        ->toContain($cleanupSentence)
        ->toContain($acceptanceSentence)
        ->toContain($waiverSentence)
        ->and($skill)
        ->toContain($ownerSentence)
        ->toContain($workersSentence)
        ->toContain($dispatchSentence)
        ->toContain($reviewerSentence)
        ->toContain($missingToolsSentence)
        ->toContain($watchSentence)
        ->toContain($receiptOwnershipSentence)
        ->toContain($heartbeatSentence)
        ->toContain($implHandoffSentence)
        ->toContain($rearmSentence)
        ->toContain($stopSentence)
        ->toContain($proofWindowSentence)
        ->toContain($cleanupSentence)
        ->and($prompt)
        ->toContain('use the feature tmux session')
        ->toContain('point Grok workers at the exact feature worktree with grok --yolo --reasoning-effort medium')
        ->toContain(
            'point one independent Claude general reviewer at that worktree with claude --dangerously-skip-permissions --model opus --effort high',
        )
        ->and($intake)
        ->toContain('hand the outcome to the orchestrating feature owner using')
        ->not->toContain('whether any bounded worker is useful')->and($intakePrompt)->toContain(
            'hand the outcome to the orchestrating feature owner using implementing-features',
        )
        ->not->toContain('optional delegation')->and($featureGraph)->toContain('"version": "4.0.0"')->toContain(
            'HARNESS-defined role policy',
        )->toContain('Grok worker via bin/orbit-worker-spawn at the exact worktree')->toContain(
            'Claude Opus general reviewer',
        )->toContain('Kill the feature tmux session; remove worktree, then branch')->toContain(
            'Codex/Grok/Claude role split',
        )
        ->not->toContain('Optional bounded workers')
        ->not->toContain('"id": "optional-worker"')
        ->not->toContain('out-of-repo operator instruction')
        ->not->toContain('implement directly');
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
            'hand the outcome to the orchestrating feature owner using implementing-features',
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
        ->toContain('## PROVE')
        ->toContain('## ACCEPT')
        ->toContain('## LAND')
        ->toContain('bin/orbit-feature-acceptance route')
        ->toContain('bin/orbit-feature-acceptance ready')
        ->toContain('bin/orbit-feature-acceptance accept')
        ->toContain('bin/orbit-feature-land')
        ->toContain('bin/orbit-feature-finalization-check')
        ->toContain('bin/orbit-session-archive')
        ->toContain('bin/orbit-feature-feedback')
        ->toContain('The implementer owns focused checks and the one terminal gate; owner')
        ->toContain('candidate=<40-character sha>')
        ->toContain('bin/orbit-feature-proof-receipt')
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
