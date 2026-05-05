<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Apps\SetAppAgentIdeRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('sets an app-level agent ide adapter from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $config = App::query()->where('name', 'docs')->value('agent_ide_config');

    expect($exitCode)->toBe(0)
        ->and($config)->toBe(['adapter' => 'opencode'])
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => 'opencode',
            'source' => 'app',
            'effective_adapter' => 'opencode',
        ])
        ->and($payload['success']['data']['cleanup'])->toBe([
            'workspaces_removed' => [],
        ])
        ->and($payload['success']['data']['action'])->toBe('set');
});

it('reports converged when the app-level adapter already matches', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['action'])->toBe('converged')
        ->and($payload['success']['data']['agent_ide']['effective_adapter'])->toBe('opencode');
});

it('clears the app override and inherits the owning node default', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'inherit',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull()
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => null,
            'source' => 'node',
            'effective_adapter' => 'polyscope',
        ]);
});

it('stores none as an explicit app-level disable', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'none',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe(['adapter' => 'none'])
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => 'none',
            'source' => 'app',
            'effective_adapter' => null,
        ]);
});

it('denies app callers before side effects', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull();
});

it('rejects unsupported adapters with the supported value list', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'unknown',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('app.unsupported_adapter')
        ->and($payload['error']['meta'])->toMatchArray([
            'adapter' => 'unknown',
            'supported' => ['inherit', 'none', 'opencode', 'polyscope'],
        ]);
});

it('validates missing required non-interactive inputs', function (?string $app, ?string $agentIde, string $field): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => $app,
        'agent_ide' => $agentIde,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe($field);
})->with([
    'missing app' => [null, 'opencode', 'app'],
    'missing agent ide' => ['docs', null, 'agent_ide'],
]);

it('forwards configured control callers through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    App::factory()->create([
        'name' => 'docs',
    ]);

    MockClient::global([
        SetAppAgentIdeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'effective_adapter' => 'opencode',
                    ],
                    'cleanup' => [
                        'workspaces_removed' => [],
                    ],
                    'action' => 'set',
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull()
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and($payload['success']['data']['agent_ide']['effective_adapter'])->toBe('opencode')
        ->and($payload['success']['data']['cleanup']['workspaces_removed'])->toBe([]);
});

it('prompts for missing human input and renders the progress tree', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $this->artisan('app:agent-ide')
        ->expectsQuestion('App name or hostname', 'docs')
        ->expectsChoice('Select agent IDE adapter', 'opencode', [
            'inherit' => 'Inherit node default',
            'none' => 'None',
            'opencode' => 'opencode',
            'polyscope' => 'polyscope',
        ])
        ->expectsOutputToContain('┌ Configuring App Agent IDE')
        ->expectsOutputToContain('○ Validate adapter')
        ->expectsOutputToContain('○ Check for workspace cleanup')
        ->expectsOutputToContain('○ Apply and verify app agent IDE')
        ->expectsOutputToContain('└ App `docs` agent IDE set to `opencode` (effective: `opencode`)')
        ->assertExitCode(0);
});

it('renders inherited and explicit none human success states', function (string $input, string $expected): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $this->artisan('app:agent-ide docs '.$input)
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'inherit' => ['inherit', '└ App `docs` agent IDE set to inherit (effective: `polyscope` from node `app-1`)'],
    'none' => ['none', '└ App `docs` agent IDE set to none (effective: none)'],
]);

it('renders converged human output', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $this->artisan('app:agent-ide docs opencode')
        ->expectsOutputToContain('└ App `docs` agent IDE already set to `opencode`')
        ->assertExitCode(0);
});
