<?php

declare(strict_types=1);

namespace App\Librarian;

use RuntimeException;

final readonly class MonorepoUnitMapBuilder
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const array UNIT_DEFINITIONS = [
        'root' => [
            'type' => 'repository-root',
            'purpose' => 'Orchestration, harness, root Composer gates, bin launchers, and cross-project configuration. There is no root Laravel app.',
            'start_when' => [
                'Choosing the owning app or package for a new change.',
                'Preparing feature worktrees, quality gates, release flow, or eval workflow.',
            ],
            'do_not_start_when' => [
                'Writing app behavior without first routing to the owning app or package.',
                'Running root phpunit, root artisan, or root Mago as if Orbit had a root Laravel app.',
            ],
            'owning_paths' => [
                'AGENTS.md',
                'HARNESS.md',
                'HARNESS_SIGNALS.md',
                'PRODUCT_DECISIONS.md',
                'bin',
                'composer.json',
                '.agents/skills',
            ],
            'entrypoints' => [
                'bin/orbit-prepare-worktree',
                'composer docs-lint',
                'composer quality-check',
            ],
            'authority_docs' => [
                'AGENTS.md',
                'HARNESS.md',
                'PRODUCT_DECISIONS.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
                '.agents/skills/orbit-evals/SKILL.md',
                '.agents/skills/quality-gate-triage/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'composer docs-lint',
                    'composer quality-check',
                ],
                'path_argument_rules' => [
                    'The repository root has no app test directory, root artisan, root PHPUnit, or root Mago config.',
                    'For root orchestration changes, prefer root Composer scripts; use app/package-focused commands only after routing to that owning unit.',
                    'When using an app wrapper such as bin/orbit-gateway-pest, pass paths relative to that app, for example tests/Feature/ExampleTest.php, not apps/gateway/tests/Feature/ExampleTest.php.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => '',
        ],
        'apps-agent' => [
            'type' => 'rust-agent-service',
            'purpose' => 'Headless Rust/Axum Orbit Agent service binary. It owns local agent config loading, gateway ping/status, the Agent listener HTTP surface, gateway-pushed command execution, and app-local Rust tests.',
            'start_when' => [
                'Changing the headless Orbit Agent service, local agent config, health/status HTTP surface, gateway ping, Agent listener, or pushed command execution.',
                'Verifying Orbit Agent Rust service build, formatting, linting, or focused unit tests.',
            ],
            'do_not_start_when' => [
                'Changing macOS menu-bar runtime, tray/menu status behavior, Tauri config, or native macOS UI packaging; use apps/macos.',
                'Changing gateway-side Agent command dispatch or operation-token verification endpoint implementation instead of the local Agent listener boundary.',
                'Adding autostart installers, signing, notarization, DMG packaging, self-update, arbitrary privileged shell execution, approval UI, WebSocket presence, or SSH/RemoteShell replacement behavior.',
            ],
            'owning_paths' => [
                'apps/agent',
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
                'apps/docs/content/domains/1_node/node-concepts.md',
            ],
            'entrypoints' => [
                'apps/agent/Cargo.toml',
                'apps/agent/src/main.rs',
            ],
            'authority_docs' => [
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
                'apps/docs/content/domains/1_node/node-concepts.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'cd apps/agent && cargo test',
                    'cd apps/agent && cargo fmt -- --check',
                    'cd apps/agent && cargo check',
                    'cd apps/agent && cargo clippy --all-targets -- -D warnings',
                ],
                'path_argument_rules' => [
                    'Run focused headless Rust service checks from apps/agent while developing; root composer quality-check includes the same apps/agent Cargo subgates for broad handoff.',
                    'Keep native tray/menu and Tauri verification in apps/macos.',
                    'Do not suggest composer test:e2e* commands for Orbit Agent runtime bootstrap verification.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/agent',
        ],
        'apps-macos' => [
            'type' => 'tauri-macos-agent-ui',
            'purpose' => 'macOS-only Tauri tray app for Orbit Agent UI. It owns native tray/menu status behavior and queries the independent headless Orbit Agent service instead of owning the long-running background service loop.',
            'start_when' => [
                'Changing the macOS Orbit Agent menu-bar runtime, tray/menu status behavior, Tauri config, native assets, or service-status UI.',
                'Verifying Orbit Agent macOS Rust/Tauri build, formatting, linting, or focused unit tests.',
            ],
            'do_not_start_when' => [
                'Changing the headless service Agent listener, pushed command execution, or Axum health/status service; use apps/agent.',
                'Changing gateway-side Agent command dispatch or operation-token verification endpoint implementation instead of the local Agent listener boundary.',
                'Adding launchd packaging, signing, notarization, DMG packaging, self-update, arbitrary privileged shell execution, approval UI, WebSocket presence, or SSH/RemoteShell replacement behavior.',
            ],
            'owning_paths' => [
                'apps/macos',
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
                'apps/docs/content/domains/1_node/node-concepts.md',
            ],
            'entrypoints' => [
                'apps/macos/Cargo.toml',
                'apps/macos/tauri.conf.json',
            ],
            'authority_docs' => [
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
                'apps/docs/content/domains/1_node/node-concepts.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
                '.agents/skills/tauri-agent-development/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'cd apps/macos && cargo test',
                    'cd apps/macos && cargo fmt -- --check',
                    'cd apps/macos && cargo check',
                    'cd apps/macos && cargo clippy --all-targets -- -D warnings',
                ],
                'path_argument_rules' => [
                    'Run focused Cargo/Tauri-compatible Rust checks from apps/macos while developing; root composer quality-check includes the same apps/macos Cargo subgates for broad handoff.',
                    'Use host-Mac topology proof for native tray/menu behavior; retained Incus topology does not prove macOS Tauri UI behavior.',
                    'Do not suggest composer test:e2e* commands for Orbit Agent macOS UI verification.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/macos',
        ],
        'apps-cli' => [
            'type' => 'laravel-zero-cli',
            'purpose' => 'Local Orbit CLI and executor application. It owns command classes, human/JSON rendering, local config, gateway client calls, and CLI Pest coverage.',
            'start_when' => [
                'Changing an `orbit ...` command, CLI renderer, local executor, or CLI-to-SDK request path.',
                'Debugging command output, input validation, or local CLI configuration behavior.',
            ],
            'do_not_start_when' => [
                'Changing gateway HTTP/API implementation after the CLI request boundary.',
                'Changing shared cross-app contracts that belong in packages/core.',
            ],
            'owning_paths' => [
                'apps/cli',
                'apps/docs/content/domains',
                '.agents/skills/orbit-cli-development/SKILL.md',
                '.agents/skills/command-designer/SKILL.md',
            ],
            'entrypoints' => [
                'apps/cli/orbit',
                'bin/orbit-cli-pest',
                'apps/cli/composer.json',
            ],
            'authority_docs' => [
                'apps/docs/content/domains',
                'apps/docs/content/generated/command-catalog.json',
            ],
            'agent_skills' => [
                '.agents/skills/orbit-cli-development/SKILL.md',
                '.agents/skills/command-designer/SKILL.md',
                '.agents/skills/pest-testing/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'bin/orbit-cli-pest --compact',
                    'cd apps/cli && vendor/bin/mago format --check',
                    'cd apps/cli && vendor/bin/mago lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'bin/orbit-cli-pest changes into apps/cli; pass test paths relative to apps/cli, for example tests/Feature/Commands/App/AppListCommandTest.php, not apps/cli/tests/Feature/Commands/App/AppListCommandTest.php.',
                    'After cd apps/cli, pass Mago paths relative to apps/cli, such as app, tests, and config.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/cli',
        ],
        'apps-gateway' => [
            'type' => 'laravel-app',
            'purpose' => 'Laravel gateway/control-plane application. It owns gateway HTTP/API surface, database, provisioning services, deployed assets, and gateway-local quality tooling.',
            'start_when' => [
                'Changing gateway routes, controllers, policies, services, models, migrations, or topology/provisioning behavior.',
                'Verifying behavior that crosses from CLI/SDK into gateway HTTP or node orchestration.',
            ],
            'do_not_start_when' => [
                'Changing local CLI command rendering or CLI input handling before the SDK boundary.',
                'Treating root `php artisan` as valid; gateway Artisan is under apps/gateway.',
            ],
            'owning_paths' => [
                'apps/gateway',
                'bin/orbit-gateway-artisan',
                'bin/orbit-gateway-pest',
                'apps/docs/content',
            ],
            'entrypoints' => [
                'php apps/gateway/artisan',
                'bin/orbit-gateway-artisan',
                'bin/orbit-gateway-pest',
            ],
            'authority_docs' => [
                'apps/docs/content/architecture.md',
                'apps/docs/content/domains',
                'apps/docs/content/testing/README.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
                '.agents/skills/laravel-best-practices/SKILL.md',
                '.agents/skills/spatie-laravel-php/SKILL.md',
                '.agents/skills/pest-testing/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'bin/orbit-gateway-pest --compact',
                    'bin/orbit-gateway-vendor-bin mago format --check',
                    'bin/orbit-gateway-vendor-bin mago lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'bin/orbit-gateway-pest changes into apps/gateway; pass test paths relative to apps/gateway, for example tests/Feature/Http/Api/NodeListControllerTest.php, not apps/gateway/tests/Feature/Http/Api/NodeListControllerTest.php.',
                    'bin/orbit-gateway-vendor-bin runs gateway vendor tools from apps/gateway; pass paths such as app, tests, config, and database without an apps/gateway prefix.',
                    'Use bin/orbit-gateway-artisan or php apps/gateway/artisan for gateway Artisan; never use root php artisan.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/gateway',
        ],
        'apps-docs' => [
            'type' => 'laravel-docs-app',
            'purpose' => 'Laravel documentation and Librarian application. It owns product documentation, docs linting, command catalog generation, and generated LLM-facing documentation artifacts.',
            'start_when' => [
                'Changing product docs, Librarian lint rules, generated docs artifacts, or docs-app tests.',
                'Reconciling docs/code/test drift for command contracts or documentation authority.',
            ],
            'do_not_start_when' => [
                'Changing runtime product behavior without updating the owning app/package and tests.',
                'Hand-editing generated files without changing the generator and freshness test.',
            ],
            'owning_paths' => [
                'apps/docs',
                'apps/docs/content',
                'apps/docs/content/generated',
            ],
            'entrypoints' => [
                'bin/orbit-docs-artisan',
                'bin/orbit-docs-pest',
                'composer docs-lint',
            ],
            'authority_docs' => [
                'apps/docs/content/mission.md',
                'apps/docs/content/architecture.md',
                'apps/docs/content/concepts.md',
                'apps/docs/content/tech-stack.md',
                'apps/docs/content/domains',
            ],
            'agent_skills' => [
                '.agents/skills/librarian/SKILL.md',
                '.agents/skills/updating-documentation/SKILL.md',
                '.agents/skills/auditing-docs-drift/SKILL.md',
                '.agents/skills/pest-testing/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'bin/orbit-docs-pest --compact',
                    'composer docs-lint',
                    'cd apps/docs && vendor/bin/mago format --check',
                    'cd apps/docs && vendor/bin/mago lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'bin/orbit-docs-pest changes into apps/docs; pass test paths relative to apps/docs, for example tests/Feature/Librarian/CommandCatalogTest.php, not apps/docs/tests/Feature/Librarian/CommandCatalogTest.php.',
                    'After cd apps/docs, pass Mago paths relative to apps/docs, such as app, tests, config, and routes.',
                    'Run docs-app Artisan from the root through bin/orbit-docs-artisan, or from apps/docs after cd; never use root php artisan.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/docs',
        ],
        'apps-e2e' => [
            'type' => 'e2e-harness',
            'purpose' => 'Prepared-topology and provisioned-lane E2E harness. It owns E2E runner code and lane helpers, but agents must not trigger E2E Composer commands unless the user explicitly invokes them.',
            'start_when' => [
                'Reading or triaging existing E2E artifacts, lane maps, retained topology evidence, or E2E helper code.',
                'Documenting which manual E2E command a human should invoke.',
            ],
            'do_not_start_when' => [
                'Default feature verification can be proven with focused Pest, docs-lint, Mago, Rector, or retained topology inspection.',
                'An agent would need to run `composer test:e2e*` without explicit user invocation.',
            ],
            'owning_paths' => [
                'apps/e2e',
                'apps/docs/content/testing',
            ],
            'entrypoints' => [
                'bin/orbit-e2e-artisan',
                'apps/e2e/composer.json',
            ],
            'authority_docs' => [
                'apps/docs/content/testing/README.md',
                'apps/docs/content/testing/e2e/README.md',
                'apps/docs/content/testing/quality-gates.md',
            ],
            'agent_skills' => [
                '.agents/skills/e2e-verification-lanes/SKILL.md',
                '.agents/skills/quality-gate-triage/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [],
                'manual_only_commands' => [
                    'composer test:e2e',
                    'composer test:e2e:docker',
                    'composer test:e2e:incus',
                ],
            ],
            'tooling_base_path' => 'apps/e2e',
        ],
        'apps-reverb' => [
            'type' => 'laravel-reverb-runtime',
            'purpose' => 'Dedicated Laravel Reverb runtime app plus docker/orbit-reverb packaging for the hardimpact/orbit-reverb websocket role-node image.',
            'start_when' => [
                'Changing websocket role runtime packaging, Reverb config, or runtime container behavior.',
                'Changing docker/orbit-reverb Dockerfile or image build context for websocket role nodes.',
            ],
            'do_not_start_when' => [
                'Changing gateway websocket API contracts or CLI commands before routing through their owning units.',
                'Changing E2E prepared-topology acquisition, release manifests, or gateway role deployment code unless the request explicitly crosses from Reverb packaging into those integration surfaces.',
            ],
            'owning_paths' => [
                'apps/reverb',
                'docker/orbit-reverb',
            ],
            'entrypoints' => [
                'apps/reverb/composer.json',
            ],
            'authority_docs' => [
                'apps/docs/content/domains/1_node/node-concepts.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
                '.agents/skills/spatie-laravel-php/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'bin/orbit-gateway-vendor-bin mago --workspace ../reverb format --check',
                    'bin/orbit-gateway-vendor-bin mago --workspace ../reverb lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'apps-reverb verification is static-analysis first; do not invent gateway Pest tests for Reverb packaging unless an exact existing gateway integration test has been verified.',
                    'bin/orbit-gateway-vendor-bin uses the gateway vendor binary; --workspace ../reverb points Mago at apps/reverb from the gateway app context.',
                    'Do not suggest direct apps/e2e vendor/bin/pest commands for Reverb packaging; E2E and prepared-topology checks are manual/integration evidence unless the user explicitly asks for them.',
                    'When adding Reverb-specific focused checks, verify exact apps/reverb files or package scripts before suggesting them.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'apps/reverb',
        ],
        'packages-core' => [
            'type' => 'shared-package',
            'purpose' => 'Shared Orbit package for contracts, helpers, and cross-application primitives used across apps and packages.',
            'start_when' => [
                'Changing shared contracts, helpers, value objects, or primitives consumed by multiple Orbit units.',
            ],
            'do_not_start_when' => [
                'The behavior is app-local and can stay inside CLI, gateway, docs, or SDK boundaries.',
            ],
            'owning_paths' => [
                'packages/core',
            ],
            'entrypoints' => [
                'packages/core/composer.json',
            ],
            'authority_docs' => [
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
            ],
            'agent_skills' => [
                '.agents/skills/orbit-core-development/SKILL.md',
                '.agents/skills/spatie-laravel-php/SKILL.md',
                '.agents/skills/pest-testing/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'cd packages/core && vendor/bin/pest --compact',
                    'cd packages/core && vendor/bin/mago format --check',
                    'cd packages/core && vendor/bin/mago lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'After cd packages/core, pass Pest and Mago paths relative to packages/core, for example tests/JsonEnvelopeTest.php, not packages/core/tests/JsonEnvelopeTest.php.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'packages/core',
        ],
        'packages-sdk' => [
            'type' => 'shared-package',
            'purpose' => 'Orbit gateway API SDK, Saloon request objects, response DTOs, and Laravel SDK bindings consumed by the CLI and integrations.',
            'start_when' => [
                'Changing SDK request classes, gateway API client behavior, response DTOs, or SDK contract tests.',
                'Following command-catalog p4_mapping from CLI command to SDK request.',
            ],
            'do_not_start_when' => [
                'Changing gateway controller behavior without updating the gateway app and docs/tests.',
            ],
            'owning_paths' => [
                'packages/sdk',
            ],
            'entrypoints' => [
                'packages/sdk/composer.json',
            ],
            'authority_docs' => [
                'apps/docs/content/generated/command-catalog.json',
                'apps/docs/content/architecture.md',
            ],
            'agent_skills' => [
                '.agents/skills/orbit-sdk-development/SKILL.md',
                '.agents/skills/spatie-laravel-php/SKILL.md',
                '.agents/skills/pest-testing/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'cd packages/sdk && vendor/bin/pest --compact',
                    'cd packages/sdk && vendor/bin/mago format --check',
                    'cd packages/sdk && vendor/bin/mago lint --reporting-format=medium',
                ],
                'path_argument_rules' => [
                    'After cd packages/sdk, pass Pest and Mago paths relative to packages/sdk, for example tests/Unit/Requests/Apps/ListAppsRequestTest.php, not packages/sdk/tests/Unit/Requests/Apps/ListAppsRequestTest.php.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'packages/sdk',
        ],
        'packages-sdk-typescript' => [
            'type' => 'typescript-sdk-package',
            'purpose' => 'Generated public TypeScript gateway client. It owns the typed openapi-fetch client, the durable process-stream EventSource subscriber, and the OpenAPI-derived schema regenerated from the gateway contract.',
            'start_when' => [
                'Changing the TypeScript gateway client, process-stream subscriber, base URL resolution, or the public OpenAPI surface consumed by browser toolbar and dashboard callers.',
                'Regenerating src/generated/schema.ts from the gateway OpenAPI contract or verifying the package with its Node toolchain.',
            ],
            'do_not_start_when' => [
                'Changing gateway HTTP/API behavior or the OpenAPI export itself; route to apps/gateway first and then regenerate here.',
                'Changing the PHP Laravel SDK request/response contracts; use packages/sdk.',
            ],
            'owning_paths' => [
                'packages/sdk-typescript',
            ],
            'entrypoints' => [
                'packages/sdk-typescript/package.json',
                'packages/sdk-typescript/src/index.ts',
                'packages/sdk-typescript/openapi/public-gateway-openapi.json',
            ],
            'authority_docs' => [
                'apps/docs/content/architecture.md',
                'apps/docs/content/tech-stack.md',
            ],
            'agent_skills' => [
                '.agents/skills/implementing-features/SKILL.md',
                '.agents/skills/spatie-javascript/SKILL.md',
            ],
            'verification' => [
                'preferred_commands' => [
                    'cd packages/sdk-typescript && npm test',
                    'cd packages/sdk-typescript && npm run build',
                ],
                'path_argument_rules' => [
                    'After cd packages/sdk-typescript, run the package npm scripts (npm test, npm run typecheck, npm run build); this Node/npm package does not use Composer, Pest, or Mago.',
                    'src/generated/schema.ts is generated from the gateway OpenAPI contract; regenerate it with npm run generate instead of hand-editing.',
                ],
                'manual_only_commands' => [],
            ],
            'tooling_base_path' => 'packages/sdk-typescript',
        ],
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
        private CommandCatalogRepositoryPaths $paths,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $units = [];

        foreach (self::UNIT_DEFINITIONS as $id => $definition) {
            /** @var array{
             *     type: string,
             *     purpose: string,
             *     start_when: list<string>,
             *     do_not_start_when: list<string>,
             *     owning_paths: list<string>,
             *     entrypoints: list<string>,
             *     authority_docs: list<string>,
             *     agent_skills: list<string>,
             *     verification: array{preferred_commands: list<string>, path_argument_rules: list<string>, manual_only_commands: list<string>},
             *     tooling_base_path: string,
             * } $definition
             */
            $units[$id] = $this->buildUnit($id, $definition);
        }

        ksort($units);

        return [
            'schema_version' => 1,
            'generated_from' => [
                'unit_definitions' => 'apps/docs/app/Librarian/MonorepoUnitMapBuilder.php',
                'repo_guidance' => 'AGENTS.md',
                'harness_guidance' => 'HARNESS.md',
            ],
            'llm_contract' => [
                'purpose' => 'Compact root-start routing facts for LLM agents. Use this to choose the owning Orbit unit before reading deeper docs or source files.',
                'not_authority_for' => [
                    'Product behavior; use apps/docs/content and PRODUCT_DECISIONS.md.',
                    'Per-command implementation traces; use generated/command-catalog.json.',
                    'Replacing the active slice contract in .orbit/loop.md.',
                ],
            ],
            'units' => $units,
        ];
    }

    public function artifactPath(): string
    {
        return "{$this->docs->docsRoot()}/generated/monorepo-unit-map.json";
    }

    /**
     * @param  array<string, mixed>  $map
     */
    public function toJson(array $map): string
    {
        return json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function write(): string
    {
        $path = $this->artifactPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, permissions: 0o775, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create monorepo unit map directory: {$directory}");
        }

        if (file_put_contents($path, $this->toJson($this->build())) === false) {
            throw new RuntimeException("Unable to write monorepo unit map: {$path}");
        }

        return $path;
    }

    public function isFresh(): bool
    {
        $path = $this->artifactPath();

        return is_file($path) && file_get_contents($path) === $this->toJson($this->build());
    }

    /**
     * @param  array{
     *     type: string,
     *     purpose: string,
     *     start_when: list<string>,
     *     do_not_start_when: list<string>,
     *     owning_paths: list<string>,
     *     entrypoints: list<string>,
     *     authority_docs: list<string>,
     *     agent_skills: list<string>,
     *     verification: array{preferred_commands: list<string>, path_argument_rules: list<string>, manual_only_commands: list<string>},
     *     tooling_base_path: string,
     * }  $definition
     * @return array<string, mixed>
     */
    private function buildUnit(string $id, array $definition): array
    {
        return [
            'id' => $id,
            'type' => $definition['type'],
            'purpose' => $definition['purpose'],
            'start_when' => $definition['start_when'],
            'do_not_start_when' => $definition['do_not_start_when'],
            'owning_paths' => $definition['owning_paths'],
            'entrypoints' => $definition['entrypoints'],
            'authority_docs' => $definition['authority_docs'],
            'agent_skills' => $definition['agent_skills'],
            'verification' => $definition['verification'],
            'facts' => [
                'paths_exist' => $this->pathsExist($definition['owning_paths']),
                'tooling' => $this->toolingFacts($definition['tooling_base_path']),
            ],
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, bool>
     */
    private function pathsExist(array $paths): array
    {
        $facts = [];

        foreach ($paths as $path) {
            $facts[$path] = file_exists($this->paths->absolutePath($path));
        }

        ksort($facts);

        return $facts;
    }

    /**
     * @return array<string, string|null>
     */
    private function toolingFacts(string $basePath): array
    {
        $existingPath = function (string $leaf) use ($basePath): ?string {
            $path = $basePath === '' ? $leaf : "{$basePath}/{$leaf}";

            if (is_file($this->paths->absolutePath($path))) {
                return $path;
            }

            return null;
        };

        return [
            'cargo_toml' => $existingPath('Cargo.toml'),
            'composer_json' => $existingPath('composer.json'),
            'tauri_config' => $existingPath('tauri.conf.json'),
            'package_json' => $existingPath('package.json'),
            'phpunit_xml' => $existingPath('phpunit.xml'),
            'mago_config' => $existingPath('mago.toml'),
            'rector_config' => $existingPath('rector.php'),
        ];
    }
}
