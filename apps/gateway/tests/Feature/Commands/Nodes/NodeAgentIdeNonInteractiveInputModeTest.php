<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

function setupNodeAgentIdeNonInteractiveAppCaller(): void
{
    DB::table('nodes')->insert([
        'name' => 'app-caller',
        'host' => '10.6.0.99',
        'wireguard_address' => '10.6.0.99',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

describe('node:agent-ide non-interactive input mode', function (): void {
    it('returns documented validation JSON for missing required input in json mode', function (): void {
        $exitCode = Artisan::call('node:agent-ide', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => 'Node name is required.',
                'meta' => [
                    'field' => 'name',
                ],
            ]);
    });

    it('returns documented validation JSON for missing adapter in json mode', function (): void {
        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => 'Agent IDE adapter is required.',
                'meta' => [
                    'field' => 'agent_ide',
                ],
            ]);
    });

    it('returns the gateway invalid-value JSON for unsupported adapters in json mode', function (): void {
        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.2',
            'gateway_wg_ip' => '10.6.0.2',
            'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
        ])->save();

        MockClient::global([
            SetNodeAgentIdeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'node.unsupported_adapter',
                    'message' => "Adapter 'unknown-ide' is not supported.",
                    'meta' => [
                        'adapter' => 'unknown-ide',
                    ],
                ],
            ], 422),
        ]);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'unknown-ide',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'node.unsupported_adapter',
                'message' => "Adapter 'unknown-ide' is not supported.",
                'meta' => [
                    'adapter' => 'unknown-ide',
                ],
            ]);
    });

    it('sends non-gateway callers to the gateway write request and preserves grant denial', function (): void {
        setupNodeAgentIdeNonInteractiveAppCaller();

        $mock = MockClient::global([
            SetNodeAgentIdeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => "This node is not authorized for 'node:agent' on 'app-1'.",
                    'meta' => [
                        'reason' => 'missing_permission',
                        'missing_permission' => 'node:agent',
                        'serving_node' => 'app-1',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'authorization_failed',
                'message' => "This node is not authorized for 'node:agent' on 'app-1'.",
                'meta' => [
                    'reason' => 'missing_permission',
                    'missing_permission' => 'node:agent',
                    'serving_node' => 'app-1',
                ],
            ]);

        $mock->assertSentCount(1);
        $mock->assertSent(fn (SetNodeAgentIdeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1/agent-ide'
            && $request->body()->all() === [
                'agent_ide' => 'opencode',
            ]);
    });
});
