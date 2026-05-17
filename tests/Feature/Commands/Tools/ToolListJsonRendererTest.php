<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Tools\ListToolsRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolListJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-list-json-{$role}",
        'role' => $role,
        'host' => '10.7.0.1',
        'wireguard_address' => '10.7.0.1',
    ]);
}

function configureToolListJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolListJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.7.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('tool:list JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits canonical tool entities', function (): void {
        createToolListJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-1', 'role' => 'app']);

        NodeTool::factory()->create([
            'name' => 'redis',
            'node_id' => $node->id,
            'expected_state' => 'running',
            'expected_version' => '7.2',
            'config' => [
                'endpoints' => [
                    [
                        'name' => 'redis',
                        'kind' => 'tcp',
                        'host' => 'redis.app-json-1.test',
                        'port' => 6379,
                    ],
                ],
            ],
        ]);

        $exitCode = Artisan::call('tool:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and($payload['success']['data']['tools'])->toHaveCount(1);

        $tool = $payload['success']['data']['tools'][0];

        expect(array_keys($tool))->toBe([
            'name',
            'node',
            'expected_state',
            'observed_state',
            'version',
            'managed',
            'endpoints',
        ])
            ->and($tool)->toMatchArray([
                'name' => 'redis',
                'node' => 'app-json-1',
                'expected_state' => 'running',
                'version' => '7.2',
                'managed' => true,
            ])
            // tool:list is registry-read only; observed_state is schema-stable null until a live option exists.
            ->and($tool['observed_state'])->toBeNull()
            ->and($tool['endpoints'])->toBe([
                [
                    'name' => 'redis',
                    'kind' => 'tcp',
                    'host' => 'redis.app-json-1.test',
                    'port' => 6379,
                ],
            ]);
    });

    it('omits observed liveness columns from the human registry renderer', function (): void {
        createToolListJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-human-1', 'role' => 'app']);

        NodeTool::factory()->create([
            'name' => 'redis',
            'node_id' => $node->id,
            'expected_state' => 'running',
            'expected_version' => '7.2',
        ]);

        $exitCode = Artisan::call('tool:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Tool')
            ->and($output)->toContain('Expected')
            ->and($output)->toContain('Version')
            ->and($output)->toContain('Managed')
            ->and($output)->not->toContain('Observed')
            ->and($output)->not->toContain('(not observed)');
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolListJsonControlGateway();

        MockClient::global([
            ListToolsRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to read the tool registry.',
            'meta' => ['caller_role' => 'control'],
        ], 403],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot reach the tool registry.',
            'meta' => ['reason' => 'connect_timeout'],
        ], 503],
    ]);
});
