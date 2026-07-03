<?php

declare(strict_types=1);

use App\Librarian\MonorepoUnitMapBuilder;
use Illuminate\Support\Facades\Artisan;

it('builds compact routing entries for every known Orbit unit', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();

    expect($map)
        ->toHaveKey('schema_version', 1)
        ->toHaveKey('generated_from.unit_definitions', 'apps/docs/app/Librarian/MonorepoUnitMapBuilder.php')
        ->toHaveKey('llm_contract.purpose')
        ->and(array_keys($map['units']))
        ->toBe([
            'apps-agent',
            'apps-cli',
            'apps-docs',
            'apps-e2e',
            'apps-gateway',
            'apps-macos',
            'apps-reverb',
            'packages-core',
            'packages-sdk',
            'root',
        ]);
});

it('keeps root routing explicit about orchestration and missing root Laravel tooling', function (): void {
    $root = app(MonorepoUnitMapBuilder::class)->build()['units']['root'];

    expect($root)
        ->toHaveKey('purpose')
        ->and($root['purpose'])
        ->toContain('There is no root Laravel app')
        ->and($root['entrypoints'])
        ->toContain('bin/orbit-prepare-worktree')
        ->and($root['verification']['preferred_commands'])
        ->toContain('composer quality-check')
        ->and($root['facts']['tooling'])
        ->toMatchArray([
            'composer_json' => 'composer.json',
            'package_json' => null,
            'phpunit_xml' => null,
            'mago_config' => null,
            'rector_config' => null,
        ]);
});

it('maps app and package units to their local tooling and preferred verification', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();

    expect($map['units']['apps-cli'])
        ->toHaveKey('facts.tooling.composer_json', 'apps/cli/composer.json')
        ->toHaveKey('facts.tooling.phpunit_xml', 'apps/cli/phpunit.xml')
        ->and($map['units']['apps-cli']['entrypoints'])
        ->toContain('apps/cli/orbit')
        ->and($map['units']['apps-cli']['verification']['preferred_commands'])
        ->toContain('bin/orbit-cli-pest --compact');

    expect($map['units']['apps-gateway'])
        ->toHaveKey('facts.tooling.composer_json', 'apps/gateway/composer.json')
        ->toHaveKey('facts.tooling.package_json', 'apps/gateway/package.json')
        ->and($map['units']['apps-gateway']['entrypoints'])
        ->toContain('bin/orbit-gateway-artisan')
        ->and($map['units']['apps-gateway']['verification']['preferred_commands'])
        ->toContain('bin/orbit-gateway-pest --compact');

    expect($map['units']['packages-sdk'])
        ->toHaveKey('facts.tooling.composer_json', 'packages/sdk/composer.json')
        ->and($map['units']['packages-sdk']['verification']['preferred_commands'])
        ->toContain('cd packages/sdk && vendor/bin/pest --compact');
});

it('maps the Orbit Agent service and macOS UI to separate Cargo verification', function (): void {
    $agent = app(MonorepoUnitMapBuilder::class)->build()['units']['apps-agent'];
    $macos = app(MonorepoUnitMapBuilder::class)->build()['units']['apps-macos'];

    expect($agent)
        ->toHaveKey('type', 'rust-agent-service')
        ->toHaveKey('facts.tooling.cargo_toml', 'apps/agent/Cargo.toml')
        ->toHaveKey('facts.tooling.tauri_config', null)
        ->and($agent['purpose'])
        ->toContain('Headless Rust/Axum Orbit Agent service binary')
        ->and($agent['verification']['preferred_commands'])
        ->toContain('cd apps/agent && cargo test')
        ->toContain('cd apps/agent && cargo fmt -- --check')
        ->toContain('cd apps/agent && cargo check')
        ->toContain('cd apps/agent && cargo clippy --all-targets -- -D warnings')
        ->and($agent['verification']['path_argument_rules'])
        ->toContain(
            'Run focused headless Rust service checks from apps/agent while developing; root composer quality-check includes the same apps/agent Cargo subgates for broad handoff.',
        )
        ->not
        ->toContain(
            'Run Cargo/Tauri-compatible Rust checks from apps/agent; do not route them through root Composer, Laravel Artisan, Pest, or Mago wrappers.',
        )
        ->and($agent['do_not_start_when'][1])
        ->toContain('gateway claim/report endpoint')
        ->and($macos)
        ->toHaveKey('type', 'tauri-macos-agent-ui')
        ->toHaveKey('facts.tooling.cargo_toml', 'apps/macos/Cargo.toml')
        ->toHaveKey('facts.tooling.tauri_config', 'apps/macos/tauri.conf.json')
        ->and($macos['purpose'])
        ->toContain('queries the independent headless Orbit Agent service')
        ->and($macos['verification']['preferred_commands'])
        ->toContain('cd apps/macos && cargo test')
        ->toContain('cd apps/macos && cargo fmt -- --check')
        ->toContain('cd apps/macos && cargo check')
        ->toContain('cd apps/macos && cargo clippy --all-targets -- -D warnings')
        ->and($macos['agent_skills'])
        ->toContain('.agents/skills/tauri-agent-development/SKILL.md')
        ->and($macos['do_not_start_when'][2])
        ->toContain('self-update')
        ->toContain('arbitrary privileged shell execution')
        ->toContain('SSH/RemoteShell replacement');
});

it('marks E2E commands as manual-only instead of preferred agent verification', function (): void {
    $e2e = app(MonorepoUnitMapBuilder::class)->build()['units']['apps-e2e'];

    expect($e2e['verification']['preferred_commands'])
        ->toBeEmpty()
        ->and($e2e['verification']['manual_only_commands'])
        ->toContain('composer test:e2e')
        ->and($e2e['do_not_start_when'][1])
        ->toContain('without explicit user invocation');
});

it('does not leak worktree paths into the generated map', function (): void {
    $json = app(MonorepoUnitMapBuilder::class)->toJson(app(MonorepoUnitMapBuilder::class)->build());

    expect($json)
        ->not->toContain('.worktrees')
        ->not->toContain('/Users/');
});

it('only points owning_paths at files or directories that currently exist', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();

    foreach ($map['units'] as $unit) {
        expect($unit['facts']['paths_exist'])->each->toBeTrue();
    }
});

it('only points agent skills and authority docs at existing repository paths', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();
    $repoRoot = dirname(base_path(), levels: 2);
    $missing = [];

    foreach ($map['units'] as $unit) {
        foreach ([...$unit['agent_skills'], ...$unit['authority_docs']] as $path) {
            if (file_exists("{$repoRoot}/{$path}")) {
                continue;
            }

            $missing[] = "{$unit['id']}: {$path}";
        }
    }

    expect($missing)->toBeEmpty();
});

it('does not suggest root Laravel commands or runnable E2E commands as preferred verification', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();
    $invalid = [];

    foreach ($map['units'] as $unit) {
        foreach ($unit['verification']['preferred_commands'] as $command) {
            if (
                ! str_contains($command, 'test:e2e')
                && ! str_contains($command, 'apps/e2e && vendor/bin/pest')
                && ! str_starts_with($command, 'php artisan')
                && ! str_starts_with($command, 'vendor/bin/phpunit')
                && $command !== 'phpunit'
            ) {
                continue;
            }

            $invalid[] = "{$unit['id']}: {$command}";
        }
    }

    expect($invalid)->toBeEmpty();
});

it('documents wrapper-relative verification path semantics for LLM agents', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();

    expect($map['units']['apps-cli']['verification']['path_argument_rules'])
        ->toContain(
            'bin/orbit-cli-pest changes into apps/cli; pass test paths relative to apps/cli, for example tests/Feature/Commands/App/AppListCommandTest.php, not apps/cli/tests/Feature/Commands/App/AppListCommandTest.php.',
        );

    expect($map['units']['apps-gateway']['verification']['path_argument_rules'])
        ->toContain(
            'bin/orbit-gateway-pest changes into apps/gateway; pass test paths relative to apps/gateway, for example tests/Feature/Http/Api/NodeListControllerTest.php, not apps/gateway/tests/Feature/Http/Api/NodeListControllerTest.php.',
        )
        ->toContain(
            'Use bin/orbit-gateway-artisan or php apps/gateway/artisan for gateway Artisan; never use root php artisan.',
        );

    expect($map['units']['apps-docs']['verification']['path_argument_rules'])
        ->toContain(
            'bin/orbit-docs-pest changes into apps/docs; pass test paths relative to apps/docs, for example tests/Feature/Librarian/CommandCatalogTest.php, not apps/docs/tests/Feature/Librarian/CommandCatalogTest.php.',
        );

    expect($map['units']['packages-core']['verification']['path_argument_rules'])
        ->toContain(
            'After cd packages/core, pass Pest and Mago paths relative to packages/core, for example tests/JsonEnvelopeTest.php, not packages/core/tests/JsonEnvelopeTest.php.',
        );

    expect($map['units']['packages-sdk']['verification']['path_argument_rules'])
        ->toContain(
            'After cd packages/sdk, pass Pest and Mago paths relative to packages/sdk, for example tests/Unit/Requests/Apps/ListAppsRequestTest.php, not packages/sdk/tests/Unit/Requests/Apps/ListAppsRequestTest.php.',
        );
});

it('keeps preferred Mago format checks broad enough for each routed unit', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();

    expect($map['units']['apps-cli']['verification']['preferred_commands'])
        ->toContain('cd apps/cli && vendor/bin/mago format --check');

    expect($map['units']['apps-gateway']['verification']['preferred_commands'])
        ->toContain('bin/orbit-gateway-vendor-bin mago format --check');

    expect($map['units']['apps-docs']['verification']['preferred_commands'])
        ->toContain('cd apps/docs && vendor/bin/mago format --check');

    expect($map['units']['packages-core']['verification']['preferred_commands'])
        ->toContain('cd packages/core && vendor/bin/mago format --check');

    expect($map['units']['packages-sdk']['verification']['preferred_commands'])
        ->toContain('cd packages/sdk && vendor/bin/mago format --check');
});

it('warns agents not to invent gateway Pest paths for Reverb runtime packaging', function (): void {
    $reverb = app(MonorepoUnitMapBuilder::class)->build()['units']['apps-reverb'];

    expect($reverb['owning_paths'])
        ->toContain('docker/orbit-reverb');

    expect($reverb['verification']['path_argument_rules'])
        ->toContain(
            'apps-reverb verification is static-analysis first; do not invent gateway Pest tests for Reverb packaging unless an exact existing gateway integration test has been verified.',
        )
        ->toContain(
            'bin/orbit-gateway-vendor-bin uses the gateway vendor binary; --workspace ../reverb points Mago at apps/reverb from the gateway app context.',
        )
        ->toContain(
            'Do not suggest direct apps/e2e vendor/bin/pest commands for Reverb packaging; E2E and prepared-topology checks are manual/integration evidence unless the user explicitly asks for them.',
        );
});

it('keeps preferred verification commands pointed at current repo entrypoints', function (): void {
    $map = app(MonorepoUnitMapBuilder::class)->build();
    $repoRoot = dirname(base_path(), levels: 2);
    $rootComposer = json_decode(
        file_get_contents("{$repoRoot}/composer.json"),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $unknown = [];

    foreach ($map['units'] as $unit) {
        foreach ($unit['verification']['preferred_commands'] as $command) {
            if (str_starts_with($command, 'composer ')) {
                $script = explode(' ', substr($command, strlen('composer ')))[0] ?? '';

                if (array_key_exists($script, $rootComposer['scripts'] ?? [])) {
                    continue;
                }
            }

            if (str_starts_with($command, 'bin/')) {
                $executable = explode(' ', $command)[0];

                if (is_file("{$repoRoot}/{$executable}")) {
                    continue;
                }
            }

            if (str_starts_with($command, 'cd ') && str_contains($command, ' && ')) {
                [$cdPart, $nestedCommand] = explode(separator: ' && ', string: $command, limit: 2);
                $directory = substr($cdPart, strlen('cd '));
                $executable = explode(' ', $nestedCommand)[0];

                if (is_file("{$repoRoot}/{$directory}/{$executable}")) {
                    continue;
                }

                if ($executable === 'cargo' && is_file("{$repoRoot}/{$directory}/Cargo.toml")) {
                    continue;
                }
            }

            $unknown[] = "{$unit['id']}: {$command}";
        }
    }

    expect($unknown)->toBeEmpty();
});

it('keeps the committed monorepo unit map in sync with the generated map', function (): void {
    $builder = app(MonorepoUnitMapBuilder::class);
    $expected = $builder->toJson($builder->build());
    $path = base_path('content/generated/monorepo-unit-map.json');

    expect($path)->toBeFile();
    expect(file_get_contents($path))->toBe($expected);
});

it('passes the freshness check for the committed monorepo unit map', function (): void {
    $exitCode = Artisan::call('orbit:monorepo-unit-map', [
        '--check' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Orbit monorepo unit map is up to date.');
});

it('fails the freshness check when the committed monorepo unit map is stale', function (): void {
    $builder = app(MonorepoUnitMapBuilder::class);
    $path = base_path('content/generated/monorepo-unit-map.json');
    $original = file_get_contents($path);

    try {
        file_put_contents(filename: $path, data: "{\"schema_version\":0}\n");

        $exitCode = Artisan::call('orbit:monorepo-unit-map', [
            '--check' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('Orbit monorepo unit map is stale.');
    } finally {
        file_put_contents(
            filename: $path,
            data: $original === false ? $builder->toJson($builder->build()) : $original,
        );
    }
});

it('can regenerate the committed monorepo unit map through the docs app command', function (): void {
    $builder = app(MonorepoUnitMapBuilder::class);
    $path = base_path('content/generated/monorepo-unit-map.json');
    $expected = $builder->toJson($builder->build());

    file_put_contents(filename: $path, data: "{\"schema_version\":0}\n");

    try {
        $exitCode = Artisan::call('orbit:monorepo-unit-map');

        expect($exitCode)->toBe(0);
        expect($path)->toBeFile();
        expect(file_get_contents($path))->toBe($expected);
    } finally {
        file_put_contents(filename: $path, data: $expected);
    }
});

it('can print one unit entry without rewriting the artifact', function (): void {
    $path = base_path('content/generated/monorepo-unit-map.json');
    $before = file_get_contents($path);

    $exitCode = Artisan::call('orbit:monorepo-unit-map', [
        '--unit' => 'apps-cli',
    ]);

    $entry = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe($before)
        ->and($entry)
        ->toHaveKey('id', 'apps-cli')
        ->toHaveKey('type', 'laravel-zero-cli')
        ->toHaveKey('facts.tooling.composer_json', 'apps/cli/composer.json');
});

it('fails clearly when printing an unknown monorepo unit', function (): void {
    $exitCode = Artisan::call('orbit:monorepo-unit-map', [
        '--unit' => 'missing-unit',
    ]);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Unit `missing-unit` was not found in the Orbit monorepo unit map.');
});
