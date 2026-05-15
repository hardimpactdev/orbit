<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeAgentIdeJsonGateway(array|string $body, int $status = 200): MockClient
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    return MockClient::global([
        SetNodeAgentIdeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:agent-ide JSON renderer contract', function (): void {
    it('returns a discriminated success envelope with configured agent IDE fields', function (): void {
        fakeNodeAgentIdeJsonGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'node',
                    ],
                    'action' => 'set',
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data'])->toBe([
                'name' => 'app-1',
                'agent_ide' => [
                    'adapter' => 'opencode',
                    'source' => 'node',
                ],
                'action' => 'set',
            ]);
    });

    it('returns validation_failed for missing required input', function (): void {
        $exitCode = Artisan::call('node:agent-ide', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Node name is required.',
                    'meta' => [
                        'field' => 'name',
                    ],
                ],
            ]);
    });

    it('preserves documented structured gateway error codes', function (array $error): void {
        fakeNodeAgentIdeJsonGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => $error['meta']['adapter'] ?? 'opencode',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe(['error' => $error]);
    })->with([
        'caller_role_not_allowed' => [[
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from a control or gateway node.',
            'meta' => [
                'caller_role' => 'app',
            ],
        ]],
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This control node is not authorized to update node configuration.',
            'meta' => [
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ],
        ]],
        'node.not_found' => [[
            'code' => 'node.not_found',
            'message' => "Node 'missing-node' not found.",
            'meta' => [
                'name' => 'missing-node',
            ],
        ]],
        'node.unsupported_adapter' => [[
            'code' => 'node.unsupported_adapter',
            'message' => "Adapter 'unknown-ide' is not supported.",
            'meta' => [
                'adapter' => 'unknown-ide',
            ],
        ]],
    ]);

    it('renders unstructured transport failures as gateway_unavailable only', function (): void {
        fakeNodeAgentIdeJsonGateway('Service Unavailable', 503);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to update node configuration.',
                    'meta' => [],
                ],
            ]);
    });
});
