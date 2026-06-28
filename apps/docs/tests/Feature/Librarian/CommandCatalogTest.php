<?php

declare(strict_types=1);

use App\Librarian\CommandCatalogBuilder;
use App\Librarian\CommandCatalogCliEndpointExtractor;
use App\Librarian\CommandCatalogVerificationHints;
use Illuminate\Support\Facades\Artisan;

it('builds a command catalog from the live CLI surface and docs registries', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog)->toHaveKey('schema_version', 1)->toHaveKeys(['generated_from', 'commands', 'registries']);

    expect($catalog['generated_from'])->toMatchArray([
        'cli_surface' => 'apps/cli/orbit list --format=json',
        'docs_registry' => 'apps/docs/config/librarian-command-docs',
    ]);

    expect($catalog['commands'])->toBeArray()->not->toBeEmpty()->toHaveKey('tool:install');

    expect($catalog['commands']['tool:install'])->toMatchArray([
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

    expect($catalog['registries'])->toHaveKeys([
        'error_codes',
        'warning_codes',
        'entity_schemas',
        'shared_options',
        'state_families',
    ]);
});

it('catalogs built-in extension commands with extension metadata', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands'])
        ->toHaveKeys([
            'extension:list',
            'extension:enable',
            'extension:disable',
            'cf-zone:list',
            'codex:app',
        ])
        ->not->toHaveKey('app:codex');

    expect($catalog['commands']['extension:list']['extension'])->toBeNull();

    expect($catalog['commands']['cf-zone:list']['extension'])->toMatchArray([
        'slug' => 'cloudflare',
        'label' => 'Cloudflare',
    ]);

    expect($catalog['commands']['codex:app']['extension'])->toMatchArray([
        'slug' => 'codex',
        'label' => 'Codex',
    ]);
});

it('maps unambiguous command endpoints to SDK and gateway implementation surfaces', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['tool:install']['p4_mapping'])->toMatchArray([
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

    expect($catalog['commands']['node:list']['p4_mapping'])->toMatchArray([
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

it('maps double-quoted interpolated gateway paths to truthful P4 metadata', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['activity:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Activity\\ShowActivityRequest',
            'path' => 'packages/sdk/src/Requests/Activity/ShowActivityRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/activity/{id}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ActivityShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ActivityShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['activity:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Activity\\ActivityShowResponse',
            'path' => 'packages/sdk/src/Responses/Activity/ActivityShowResponse.php',
        ],
    ]);

    expect($catalog['commands']['app:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Apps\\ShowAppRequest',
            'path' => 'packages/sdk/src/Requests/Apps/ShowAppRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/apps/{app}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\AppShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/AppShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['app:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Apps\\AppShowResponse',
            'path' => 'packages/sdk/src/Responses/Apps/AppShowResponse.php',
        ],
    ]);

    expect($catalog['commands']['node:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Nodes\\ShowNodeRequest',
            'path' => 'packages/sdk/src/Requests/Nodes/ShowNodeRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/nodes/{name}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\NodeShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/NodeShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => [],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Nodes\\NodeShowResponse',
            'path' => 'packages/sdk/src/Responses/Nodes/NodeShowResponse.php',
        ],
    ]);
});

it('maps rawurlencode gateway endpoint expressions to truthful P4 metadata', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['database:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Database\\ShowDatabaseConnectionRequest',
            'path' => 'packages/sdk/src/Requests/Database/ShowDatabaseConnectionRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/database-connections/{connection}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\DatabaseConnections\\DatabaseConnectionShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/DatabaseConnections/DatabaseConnectionShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['database:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Database\\DatabaseConnectionResponse',
            'path' => 'packages/sdk/src/Responses/Database/DatabaseConnectionResponse.php',
        ],
    ]);

    expect($catalog['commands']['database:add-user']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Database\\AddDatabaseUserRequest',
            'path' => 'packages/sdk/src/Requests/Database/AddDatabaseUserRequest.php',
        ],
        'gateway_route' => [
            'method' => 'POST',
            'uri' => '/api/database-connections/{connection}/users',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\DatabaseConnections\\DatabaseConnectionAddUserController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/DatabaseConnections/DatabaseConnectionAddUserController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['database:write'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Database\\DatabaseConnectionResponse',
            'path' => 'packages/sdk/src/Responses/Database/DatabaseConnectionResponse.php',
        ],
    ]);

    expect($catalog['commands']['process:logs']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Processes\\ShowProcessLogsRequest',
            'path' => 'packages/sdk/src/Requests/Processes/ShowProcessLogsRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/processes/{name}/log',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ProcessLogController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ProcessLogController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['process:logs'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Processes\\ProcessLogsResponse',
            'path' => 'packages/sdk/src/Responses/Processes/ProcessLogsResponse.php',
        ],
    ]);
});

it('maps rawurlencode tool and schedule endpoint expressions to truthful P4 metadata', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['tool:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Tools\\ShowToolRequest',
            'path' => 'packages/sdk/src/Requests/Tools/ShowToolRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/tools/{tool}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ToolShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ToolShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['tool:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Tools\\ToolShowResponse',
            'path' => 'packages/sdk/src/Responses/Tools/ToolShowResponse.php',
        ],
    ]);

    expect($catalog['commands']['schedule:logs']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Schedules\\ShowScheduleLogsRequest',
            'path' => 'packages/sdk/src/Requests/Schedules/ShowScheduleLogsRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/schedules/{name}/logs',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ScheduleLogsController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ScheduleLogsController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['schedule:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Schedules\\ScheduleLogsResponse',
            'path' => 'packages/sdk/src/Responses/Schedules/ScheduleLogsResponse.php',
        ],
    ]);

    expect($catalog['commands']['schedule:show']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Schedules\\ShowScheduleRequest',
            'path' => 'packages/sdk/src/Requests/Schedules/ShowScheduleRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/schedules/{name}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ScheduleShowController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ScheduleShowController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['schedule:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Schedules\\ScheduleShowResponse',
            'path' => 'packages/sdk/src/Responses/Schedules/ScheduleShowResponse.php',
        ],
    ]);
});

it('maps rawurlencode mutation endpoint expressions to truthful P4 metadata', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['node:update']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Nodes\\UpdateNodeRequest',
            'path' => 'packages/sdk/src/Requests/Nodes/UpdateNodeRequest.php',
        ],
        'gateway_route' => [
            'method' => 'PUT',
            'uri' => '/api/nodes/{name}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\NodeUpdateController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/NodeUpdateController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['node:update'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Nodes\\NodeUpdateResponse',
            'path' => 'packages/sdk/src/Responses/Nodes/NodeUpdateResponse.php',
        ],
    ]);

    expect($catalog['commands']['proxy:remove']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Proxy\\RemoveProxyRouteRequest',
            'path' => 'packages/sdk/src/Requests/Proxy/RemoveProxyRouteRequest.php',
        ],
        'gateway_route' => [
            'method' => 'DELETE',
            'uri' => '/api/proxy-routes/{domain}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ProxyRouteDestroyController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ProxyRouteDestroyController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['proxy:remove'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Proxy\\ProxyRouteMutationResponse',
            'path' => 'packages/sdk/src/Responses/Proxy/ProxyRouteMutationResponse.php',
        ],
    ]);

    expect($catalog['commands']['firewall:remove']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Firewall\\RemoveFirewallRuleRequest',
            'path' => 'packages/sdk/src/Requests/Firewall/RemoveFirewallRuleRequest.php',
        ],
        'gateway_route' => [
            'method' => 'DELETE',
            'uri' => '/api/firewall-rules/{name}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\FirewallRuleDestroyController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/FirewallRuleDestroyController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['firewall_rule:write'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Firewall\\FirewallRuleMutationResponse',
            'path' => 'packages/sdk/src/Responses/Firewall/FirewallRuleMutationResponse.php',
        ],
    ]);
});

it('keeps cast rawurlencode parsing narrow enough to leave unresolved catalog mappings null', function (): void {
    $extractor = app(CommandCatalogCliEndpointExtractor::class);

    $source = <<<'PHP'
        <?php

        $this->gatewayDelete('/api/processes/'.rawurlencode((string) $name).'/log', []);
        PHP;

    expect($extractor->endpoints($source))->toBe([
        [
            'method' => 'DELETE',
            'uri' => '/api/processes/{name}/log',
        ],
    ]);

    $catalog = app(CommandCatalogBuilder::class)->build();

    $emptyMapping = [
        'sdk_request' => null,
        'gateway_route' => null,
        'gateway_controller' => null,
        'authorization_permission' => null,
        'response_dto' => null,
    ];

    expect($catalog['commands']['cf-dns:add']['p4_mapping'])->toBe($emptyMapping);

    expect($catalog['commands']['schedule:remove']['p4_mapping'])->toBe($emptyMapping);
});

it('keeps truthful P4 mappings for php:use, process:add, update:all, and deploy:log', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['php:use']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Php\\UsePhpRuntimeRequest',
            'path' => 'packages/sdk/src/Requests/Php/UsePhpRuntimeRequest.php',
        ],
        'gateway_route' => [
            'method' => 'POST',
            'uri' => '/api/php/use',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\PhpUseController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/PhpUseController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => [],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Php\\PhpRuntimeUseResponse',
            'path' => 'packages/sdk/src/Responses/Php/PhpRuntimeUseResponse.php',
        ],
    ]);

    expect($catalog['commands']['process:add']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Processes\\AddProcessRequest',
            'path' => 'packages/sdk/src/Requests/Processes/AddProcessRequest.php',
        ],
        'gateway_route' => [
            'method' => 'POST',
            'uri' => '/api/processes',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\ProcessStoreController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/ProcessStoreController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['process:add'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Processes\\ProcessAddResponse',
            'path' => 'packages/sdk/src/Responses/Processes/ProcessAddResponse.php',
        ],
    ]);

    expect($catalog['commands']['update:all']['p4_mapping'])->toMatchArray([
        'sdk_request' => null,
        'gateway_route' => [
            'method' => 'POST',
            'uri' => '/api/update/all/start',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\UpdateAllStartController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/UpdateAllStartController.php',
            'action' => '__invoke',
        ],
        'authorization_permission' => ['*'],
        'response_dto' => null,
    ]);

    expect($catalog['commands']['deploy:log']['p4_mapping'])->toMatchArray([
        'sdk_request' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Requests\\Deploy\\ShowDeployLogRequest',
            'path' => 'packages/sdk/src/Requests/Deploy/ShowDeployLogRequest.php',
        ],
        'gateway_route' => [
            'method' => 'GET',
            'uri' => '/api/deploy/log/{run}',
        ],
        'gateway_controller' => [
            'class' => 'App\\Http\\Controllers\\Api\\DeployController',
            'path' => 'apps/gateway/app/Http/Controllers/Api/DeployController.php',
            'action' => 'log',
        ],
        'authorization_permission' => ['deploy:read'],
        'response_dto' => [
            'class' => 'Orbit\\Sdk\\Laravel\\Responses\\Deploy\\DeployResponse',
            'path' => 'packages/sdk/src/Responses/Deploy/DeployResponse.php',
        ],
    ]);
});

it('keeps local-only and partially parsed command mappings null', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['version']['p4_mapping'])->toBe([
        'sdk_request' => null,
        'gateway_route' => null,
        'gateway_controller' => null,
        'authorization_permission' => null,
        'response_dto' => null,
    ]);

    expect($catalog['commands']['tool:credentials']['p4_mapping'])->toBe([
        'sdk_request' => null,
        'gateway_route' => null,
        'gateway_controller' => null,
        'authorization_permission' => null,
        'response_dto' => null,
    ]);
});

it('scopes command catalog authorization metadata to controller class and action permissions', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();

    expect($catalog['commands']['cf-cache:flush']['p4_mapping']['authorization_permission'])->toBe(['cf:cache:flush']);

    expect($catalog['commands']['cf-zone:list']['p4_mapping']['authorization_permission'])->toBe(['cf:zone:list']);

    expect($catalog['commands']['manifest:update']['p4_mapping']['authorization_permission'])->toBe(['*']);

    expect($catalog['commands']['database:query']['p4_mapping']['authorization_permission'])->toBe([
        'database:query',
        'database:query:write',
    ]);

    expect($catalog['commands']['update:all']['p4_mapping']['authorization_permission'])->toBe(['*']);
});

it('keeps linked test file paths on disk under the repository root', function (): void {
    $catalog = app(CommandCatalogBuilder::class)->build();
    $repoRoot = realpath(base_path('../..'));

    expect($repoRoot)->toBeString();

    $commands = $catalog['commands'];

    expect($commands)->toBeArray()->not->toBeEmpty();

    $missing = [];

    foreach ($commands as $commandName => $command) {
        foreach ($command['linked_test_files'] as $path) {
            if (is_file("{$repoRoot}/{$path}")) {
                continue;
            }

            $missing[] = "{$commandName}: {$path}";
        }
    }

    expect($missing)->toBeEmpty(
        'Command catalog linked_test_files must reference existing repository files. Missing paths: '
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

it('passes the freshness check for the committed command catalog', function (): void {
    $exitCode = Artisan::call('orbit:command-catalog', [
        '--check' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Orbit command catalog is up to date.');
});

it('fails the freshness check when the committed command catalog is stale', function (): void {
    $builder = app(CommandCatalogBuilder::class);
    $path = base_path('content/generated/command-catalog.json');
    $original = file_get_contents($path);

    try {
        file_put_contents(filename: $path, data: "{\"schema_version\":0}\n");

        $exitCode = Artisan::call('orbit:command-catalog', [
            '--check' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('Orbit command catalog is stale.');
    } finally {
        file_put_contents(
            filename: $path,
            data: $original === false ? $builder->toJson($builder->build()) : $original,
        );
    }
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

describe('verification hints', function (): void {
    it('derives command-safe pest wrappers for CLI linked tests', function (): void {
        $catalog = app(CommandCatalogBuilder::class)->build();
        $hints = $catalog['commands']['deploy:log']['verification_hints'];

        $cliHint = collect($hints)->firstWhere(
            'repo_test_path',
            'apps/cli/tests/Feature/Commands/LogStreamCommandTest.php',
        );

        expect($cliHint)->toMatchArray([
            'repo_test_path' => 'apps/cli/tests/Feature/Commands/LogStreamCommandTest.php',
            'working_directory' => 'apps/cli',
            'runner' => 'bin/orbit-cli-pest',
            'runner_test_path' => 'tests/Feature/Commands/LogStreamCommandTest.php',
            'suggested_command' => 'bin/orbit-cli-pest --compact tests/Feature/Commands/LogStreamCommandTest.php',
        ]);

        expect($cliHint['suggested_command'])->not->toContain('apps/cli/tests');
    });

    it('derives command-safe pest wrappers for gateway linked tests', function (): void {
        $catalog = app(CommandCatalogBuilder::class)->build();
        $hints = $catalog['commands']['process:logs']['verification_hints'];

        $gatewayHint = collect($hints)->firstWhere(
            'repo_test_path',
            'apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php',
        );

        expect($gatewayHint)->toMatchArray([
            'repo_test_path' => 'apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php',
            'working_directory' => 'apps/gateway',
            'runner' => 'bin/orbit-gateway-pest',
            'runner_test_path' => 'tests/Feature/Http/Api/ProcessLogControllerTest.php',
            'suggested_command' => 'bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ProcessLogControllerTest.php',
        ]);

        expect($gatewayHint['suggested_command'])->not->toContain('apps/gateway/tests');
    });

    it('derives command-safe pest wrappers for docs linked tests', function (): void {
        $hints = app(CommandCatalogVerificationHints::class)->forLinkedTestFiles([
            'apps/docs/tests/Feature/Librarian/CommandCatalogTest.php',
        ]);

        expect($hints)->toHaveCount(1);
        expect($hints[0])->toMatchArray([
            'repo_test_path' => 'apps/docs/tests/Feature/Librarian/CommandCatalogTest.php',
            'working_directory' => 'apps/docs',
            'runner' => 'bin/orbit-docs-pest',
            'runner_test_path' => 'tests/Feature/Librarian/CommandCatalogTest.php',
            'suggested_command' => 'bin/orbit-docs-pest --compact tests/Feature/Librarian/CommandCatalogTest.php',
        ]);

        expect($hints[0]['suggested_command'])->not->toContain('apps/docs/tests');
    });

    it('derives command-safe pest wrappers for SDK linked tests', function (): void {
        $hints = app(CommandCatalogVerificationHints::class)->forLinkedTestFiles([
            'packages/sdk/tests/Unit/Requests/Processes/ShowProcessLogsRequestTest.php',
        ]);

        expect($hints)->toHaveCount(1);
        expect($hints[0])->toMatchArray([
            'repo_test_path' => 'packages/sdk/tests/Unit/Requests/Processes/ShowProcessLogsRequestTest.php',
            'working_directory' => 'packages/sdk',
            'runner' => 'vendor/bin/pest',
            'runner_test_path' => 'tests/Unit/Requests/Processes/ShowProcessLogsRequestTest.php',
            'suggested_command' => 'cd packages/sdk && vendor/bin/pest --compact tests/Unit/Requests/Processes/ShowProcessLogsRequestTest.php',
        ]);

        expect($hints[0]['suggested_command'])->not->toContain('packages/sdk/tests');
    });

    it('does not emit runnable verification hints for E2E linked tests', function (): void {
        $catalog = app(CommandCatalogBuilder::class)->build();

        expect($catalog['commands']['dns:list']['linked_test_files'])
            ->toContain(
                'apps/e2e/tests/Feature/Commands/DnsListTest.php',
            );

        $hints = $catalog['commands']['dns:list']['verification_hints'];

        expect($hints)->toHaveCount(1);
        expect(collect($hints)->pluck('repo_test_path'))
            ->not
            ->toContain(
                'apps/e2e/tests/Feature/Commands/DnsListTest.php',
            );
        expect($hints[0]['suggested_command'])
            ->toBe(
                'bin/orbit-cli-pest --compact tests/Feature/Commands/Dns/DnsListCommandTest.php',
            );

        expect(app(CommandCatalogVerificationHints::class)->forLinkedTestFile(
            'apps/e2e/tests/Feature/Commands/DnsListTest.php',
        ))->toBeNull();
    });

    it('keeps linked_test_files unchanged while adding verification_hints', function (): void {
        $catalog = app(CommandCatalogBuilder::class)->build();

        expect($catalog['commands']['deploy:log']['linked_test_files'])->toBe([
            'apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php',
            'apps/cli/tests/Feature/Commands/LogStreamCommandTest.php',
        ]);

        expect($catalog['commands']['deploy:log']['verification_hints'])->toHaveCount(2);
        expect($catalog['commands']['deploy:log']['verification_hints'][0])->toHaveKey('repo_test_path');
    });
});
