<?php

declare(strict_types=1);

use App\Librarian\CommandCatalogBuilder;
use Illuminate\Support\Facades\Artisan;

it('builds a command catalog from the live CLI surface and docs registries', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog)
        ->toHaveKey('schema_version', 1)
        ->toHaveKeys(['generated_from', 'commands', 'registries']);

    expect($catalog['generated_from'])
        ->toMatchArray([
            'cli_surface' => 'apps/cli/orbit list --format=json',
            'docs_registry' => 'apps/docs/config/librarian-command-docs',
        ]);

    expect($catalog['commands'])
        ->toBeArray()
        ->not
        ->toBeEmpty()
        ->toHaveKey('tool:install');

    expect($catalog['commands']['tool:install'])
        ->toMatchArray([
            'name' => 'tool:install',
            'slug' => 'tool-install',
            'family' => 'tool',
            'arguments' => ['tool'],
            'docs' => [
                'directory' => 'domains/3_tool/3_tool-install',
                'public' => 'domains/3_tool/3_tool-install/tool-install.md',
                'technical' => 'domains/3_tool/3_tool-install/technical/1_tool-install.md',
                'repo_directory' => 'apps/docs/content/domains/3_tool/3_tool-install',
                'repo_public' => 'apps/docs/content/domains/3_tool/3_tool-install/tool-install.md',
                'repo_technical' => 'apps/docs/content/domains/3_tool/3_tool-install/technical/1_tool-install.md',
            ],
            'public_options_documented' => [
                'json' => true,
                'stream_json' => true,
            ],
        ]);

    expect($catalog['commands']['tool:install']['options'])
        ->toContain('json')
        ->toContain('node')
        ->not->toContain('help');

    expect($catalog['commands']['tool:install']['linked_test_files'])
        ->toContain('apps/cli/tests/Feature/Commands/StreamsGatewayProgressTest.php')
        ->toContain('apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php')
        ->toContain('apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php')
        ->toContain('apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php')
        ->toContain('apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php')
        ->not->toContain('apps/gateway/tests/Feature/Commands/Tools/ToolInstallCommandTest.php')
        ->not->toContain('apps/gateway/tests/Feature/Commands/Tools/ToolInstallJsonRendererTest.php');

    expect($catalog['registries'])
        ->toHaveKeys(['error_codes', 'warning_codes', 'entity_schemas', 'shared_options', 'state_families']);
});

it('maps unambiguous command endpoints to SDK and gateway implementation surfaces', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['tool:install']['p4_mapping'])
        ->toMatchArray([
            'sdk_request' => [
                'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Tools\\InstallToolRequest',
                'path' => 'packages/sdk/src/Requests/Tools/InstallToolRequest.php',
            ],
            'gateway_route' => [
                'method' => 'POST',
                'uri' => '/api/tools/{tool}/install',
            ],
            'gateway_controller' => [
                'class' => 'App\\Http\\Controllers\\Api\\ToolInstallController',
                'path' => 'apps/gateway/app/Http/Controllers/Api/ToolInstallController.php',
                'action' => '__invoke',
            ],
            'authorization_permission' => ['tool:install'],
            'response_dto' => [
                'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Tools\\ToolInstallResponse',
                'path' => 'packages/sdk/src/Responses/Tools/ToolInstallResponse.php',
            ],
        ]);

    expect($catalog['commands']['node:list']['p4_mapping'])
        ->toMatchArray([
            'sdk_request' => [
                'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Nodes\\ListNodesRequest',
                'path' => 'packages/sdk/src/Requests/Nodes/ListNodesRequest.php',
            ],
            'gateway_route' => [
                'method' => 'GET',
                'uri' => '/api/nodes',
            ],
            'gateway_controller' => [
                'class' => 'App\\Http\\Controllers\\Api\\NodeListController',
                'path' => 'apps/gateway/app/Http/Controllers/Api/NodeListController.php',
                'action' => '__invoke',
            ],
            'authorization_permission' => [],
            'response_dto' => [
                'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Nodes\\NodeListResponse',
                'path' => 'packages/sdk/src/Responses/Nodes/NodeListResponse.php',
            ],
        ]);
});

it('keeps local-only and partially parsed command mappings null', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['version']['p4_mapping'])
        ->toBe([
            'sdk_request' => null,
            'gateway_route' => null,
            'gateway_controller' => null,
            'authorization_permission' => null,
            'response_dto' => null,
        ]);

    expect($catalog['commands']['tool:credentials']['p4_mapping'])
        ->toBe([
            'sdk_request' => null,
            'gateway_route' => null,
            'gateway_controller' => null,
            'authorization_permission' => null,
            'response_dto' => null,
        ]);
});

it('scopes command catalog authorization metadata to controller class and action permissions', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['cf-cache:flush']['p4_mapping']['authorization_permission'])
        ->toBe(['cf:cache:flush']);

    expect($catalog['commands']['cf-zone:list']['p4_mapping']['authorization_permission'])
        ->toBe(['cf:zone:list']);

    expect($catalog['commands']['manifest:update']['p4_mapping']['authorization_permission'])
        ->toBe(['*']);

    expect($catalog['commands']['database:query']['p4_mapping']['authorization_permission'])
        ->toBe(['database:query', 'database:query:write']);

    expect($catalog['commands']['update:all']['p4_mapping']['authorization_permission'])
        ->toBe(['*']);
});

it('keeps generated tool:install linked test file paths on disk under the repository root', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();
    $repoRoot = realpath(base_path('../..'));

    expect($repoRoot)->toBeString();

    $missing = [];

    foreach ($catalog['commands']['tool:install']['linked_test_files'] as $path) {
        if (is_file("{$repoRoot}/{$path}")) {
            continue;
        }

        $missing[] = $path;
    }

    expect($missing)->toBeEmpty(
        'tool:install linked_test_files must reference existing repository files. Missing paths: '
            .implode(', ', $missing),
    );
});

it('keeps the committed command catalog in sync with the generated catalog', function (): void {
    $builder = app(CommandCatalogBuilder::class);
    $expected = $builder->toJson($builder->build());
    $path = base_path('content/generated/command-catalog.json');

    expect($path)->toBeFile();
    expect(file_get_contents($path))->toBe($expected);
});

it('can regenerate the committed command catalog through the docs app command', function (): void {
    $exitCode = Artisan::call('orbit:command-catalog');

    expect($exitCode)->toBe(0);
    expect(base_path('content/generated/command-catalog.json'))->toBeFile();
});

it('can print a compact catalog entry for one command without rewriting the artifact', function (): void {
    $path = base_path('content/generated/command-catalog.json');
    $before = file_get_contents($path);

    $exitCode = Artisan::call('orbit:command-catalog', [
        '--command' => 'tool:install',
    ]);

    $entry = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe($before)
        ->and($entry)
        ->toHaveKey('name', 'tool:install')
        ->toHaveKey('docs.repo_public', 'apps/docs/content/domains/3_tool/3_tool-install/tool-install.md')
        ->toHaveKey('public_options_documented.stream_json', true);
});

it('fails clearly when printing an unknown command catalog entry', function (): void {
    $exitCode = Artisan::call('orbit:command-catalog', [
        '--command' => 'missing:nope',
    ]);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Command `missing:nope` was not found in the Orbit command catalog.');
});
