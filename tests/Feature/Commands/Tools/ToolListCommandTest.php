<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Tools\ListToolsRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolListLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('tool:list command contract', function (): void {
    it('lists local gateway tools sorted by node then tool name', function (): void {
        createToolListLocalNode('gateway');
        $zNode = Node::factory()->create(['name' => 'z-node', 'role' => 'app']);
        $aNode = Node::factory()->create(['name' => 'a-node', 'role' => 'app']);

        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $zNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $aNode->id]);
        NodeTool::factory()->create(['name' => 'caddy', 'node_id' => $aNode->id]);

        $exitCode = Artisan::call('tool:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_map(fn (array $tool): string => "{$tool['node']}:{$tool['name']}", $payload['success']['data']['tools']))->toBe([
                'a-node:caddy',
                'a-node:php',
                'z-node:redis',
            ]);
    });

    it('filters local gateway tools by node and app', function (): void {
        createToolListLocalNode('gateway');
        $firstNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $secondNode = Node::factory()->create(['name' => 'app-2', 'role' => 'app']);

        App::factory()->create(['name' => 'docs', 'node_id' => $secondNode->id]);
        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $secondNode->id]);

        $exitCode = Artisan::call('tool:list', [
            '--json' => true,
            '--node' => 'app-2',
            '--app' => 'docs',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tools'])->toHaveCount(1)
            ->and($payload['success']['data']['tools'][0]['name'])->toBe('php');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createToolListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListToolsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tools' => [
                            [
                                'name' => 'redis',
                                'node' => 'app-1',
                                'expected_state' => 'running',
                                'observed_state' => null,
                                'version' => '7.2',
                                'managed' => true,
                                'endpoints' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:list', [
            '--json' => true,
            '--node' => 'app-1',
            '--app' => 'docs',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tools'][0]['name'])->toBe('redis');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createToolListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListToolsRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the tool registry.',
                    'meta' => ['caller_role' => 'control'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('tool:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['caller_role'])->toBe('control');
    });

    it('does not mutate registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createToolListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $node->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $node->id]);

        $toolCount = DB::table('node_tools')->count();
        $nodeCount = DB::table('nodes')->count();

        $this->artisan('tool:list')->assertSuccessful();

        expect(DB::table('node_tools')->count())->toBe($toolCount)
            ->and(DB::table('nodes')->count())->toBe($nodeCount);
        Process::assertNothingRan();
    });
});
