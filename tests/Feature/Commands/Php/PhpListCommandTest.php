<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Php\ShowPhpRuntimeRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createPhpLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function createPhpTool(Node $node, array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'expected_state' => 'running',
        'config' => array_merge([
            'versions' => ['8.5', '8.4'],
            'cli_version' => '8.5',
        ], $config),
    ]);
}

describe('php:list command contract', function (): void {
    it('reports supported, installed, CLI, app, and workspace runtime facts on the gateway', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node, ['versions' => ['8.5'], 'cli_version' => '8.5']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => '8.4']);

        $exitCode = Artisan::call('php:list', [
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['php'])->toBe([
                'node' => 'app-1',
                'supported' => ['8.5', '8.4', '8.3'],
                'installed' => ['8.5'],
                'cli' => '8.5',
                'app' => [
                    'name' => 'docs',
                    'php_version' => '8.5',
                ],
                'workspace' => [
                    'name' => 'feature-docs',
                    'php_version' => '8.4',
                    'inherits' => false,
                ],
            ])
            ->and($payload['success']['meta'])->toBe([]);
    });

    it('reports workspace inheritance from the parent app', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

        Artisan::call('php:list', [
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['php']['workspace'])->toBe([
            'name' => 'feature-docs',
            'php_version' => '8.5',
            'inherits' => true,
        ]);
    });

    it('sets live metadata only when live inspection is requested', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node);

        $exitCode = Artisan::call('php:list', [
            '--node' => 'app-1',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta'])->toBe(['live' => true]);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createPhpLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowPhpRuntimeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'php' => [
                            'node' => 'app-1',
                            'supported' => ['8.5', '8.4', '8.3'],
                            'installed' => ['8.5'],
                            'cli' => '8.5',
                            'app' => null,
                            'workspace' => null,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('php:list', [
            '--node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['php']['node'])->toBe('app-1');
    });

    it('does not mutate registry state or run processes without live inspection', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node);

        $toolCount = NodeTool::query()->count();

        $this->artisan('php:list --node=app-1')->assertSuccessful();

        expect(NodeTool::query()->count())->toBe($toolCount);
        Process::assertNothingRan();
    });
});
