<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Tools\ShowToolRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
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

function createToolShowLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:show command contract', function (): void {
    it('shows a local gateway tool by node', function (): void {
        createToolShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        NodeTool::factory()->create([
            'name' => 'redis',
            'node_id' => $node->id,
            'expected_state' => 'running',
            'expected_version' => '7.2',
        ]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis')
            ->and($payload['success']['data']['tool']['node'])->toBe('app-1')
            ->and($payload['success']['data']['tool']['version'])->toBe('7.2');
    });

    it('resolves the local target node from an app selector', function (): void {
        createToolShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $node->id]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'php', '--app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('php')
            ->and($payload['success']['data']['tool']['node'])->toBe('app-1');
    });

    it('requires a target selector when the local gateway target is ambiguous', function (): void {
        createToolShowLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        Node::factory()->create(['name' => 'app-2', 'role' => 'app']);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target');
    });

    it('requires an explicit target source in non-interactive JSON even when one app node is visible', function (): void {
        createToolShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $node->id]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target');
    });

    it('uses local node default as an explicit target source', function (): void {
        createToolShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-default', 'role' => 'app']);
        LocalNodeDefault::query()->create(['default_node_name' => 'app-default']);
        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $node->id]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-default');
    });

    it('returns not found when the selected local node has no matching tool row', function (): void {
        createToolShowLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.not_found')
            ->and($payload['error']['meta']['tool'])->toBe('redis')
            ->and($payload['error']['meta']['node'])->toBe('app-1');
    });

    it('rejects unsupported tool catalog slugs before gateway lookup', function (): void {
        createToolShowLocalNode('gateway');

        $exitCode = Artisan::call('tool:show', ['tool' => 'not-a-tool', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta']['tool'])->toBe('not-a-tool');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
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
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:show', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--app' => 'docs',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');
    });

    it('preserves gateway not-found errors', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowToolRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'tool.not_found',
                    'message' => "Tool 'redis' not found on node 'app-1'.",
                    'meta' => ['tool' => 'redis', 'node' => 'app-1'],
                ],
            ], 404),
        ]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.not_found')
            ->and($payload['error']['meta']['tool'])->toBe('redis');
    });

    it('does not mutate registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createToolShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        NodeTool::factory()->create(['name' => 'redis', 'node_id' => $node->id]);

        $toolCount = DB::table('node_tools')->count();
        $nodeCount = DB::table('nodes')->count();

        $this->artisan('tool:show', ['tool' => 'redis', '--node' => 'app-1'])->assertSuccessful();

        expect(DB::table('node_tools')->count())->toBe($toolCount)
            ->and(DB::table('nodes')->count())->toBe($nodeCount);
        Process::assertNothingRan();
    });
});
