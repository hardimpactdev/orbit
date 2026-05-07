<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Tools\CredentialsToolRequest;
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

function createToolCredentialsLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('tool:credentials command contract', function (): void {
    it('reads stored credentials for a credential-bearing tool', function (): void {
        createToolCredentialsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'credentials' => [
                'fields' => [
                    'host' => 'orbit.test',
                    'port' => 6379,
                    'username' => 'orbit',
                    'password' => 'secret123',
                ],
            ],
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['credentials'])->toMatchArray([
                'tool' => 'redis',
                'node' => 'app-1',
                'fields' => [
                    'host' => 'orbit.test',
                    'port' => 6379,
                    'username' => 'orbit',
                    'password' => 'secret123',
                ],
            ]);
    });

    it('reads stored credentials for opencode-server', function (): void {
        createToolCredentialsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'expected_state' => 'running',
            'credentials' => [
                'fields' => [
                    'host' => '127.0.0.1',
                    'port' => 4096,
                    'url' => 'https://opencode.test',
                    'username' => 'orbit',
                    'password' => 'generated-secret',
                ],
            ],
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'opencode-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['credentials'])->toMatchArray([
                'tool' => 'opencode-server',
                'node' => 'app-1',
                'fields' => [
                    'host' => '127.0.0.1',
                    'port' => 4096,
                    'url' => 'https://opencode.test',
                    'username' => 'orbit',
                    'password' => 'generated-secret',
                ],
            ]);
    });

    it('rejects tools without credential support', function (): void {
        createToolCredentialsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'caddy',
                'action' => 'credentials',
            ]);
    });

    it('rejects polyscope-server credential requests', function (): void {
        createToolCredentialsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'polyscope-server',
            'expected_state' => 'running',
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'polyscope-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'polyscope-server',
                'action' => 'credentials',
            ]);
    });

    it('fails when no credentials are stored', function (): void {
        createToolCredentialsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'credentials' => null,
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createToolCredentialsLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            CredentialsToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'credentials' => [
                            'tool' => 'redis',
                            'node' => 'app-1',
                            'fields' => [
                                'host' => 'orbit.test',
                                'port' => 6379,
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:credentials', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['credentials']['tool'])->toBe('redis');
    });
});
