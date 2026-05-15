<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRemoveAppContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-caller',
        'role' => 'app',
        'host' => '10.6.0.9',
        'wireguard_address' => '10.6.0.9',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRemoveAppContractCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRemoveAppContractRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

describe('node:remove on app node contract', function (): void {
    it('preserves gateway caller_role_not_allowed JSON errors for app-role CLI callers', function (): void {
        setupNodeRemoveAppContractCaller();

        $mock = MockClient::global([
            RemoveNodeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'caller_role_not_allowed',
                    'message' => 'This command may only be run from a control or gateway node.',
                    'meta' => [
                        'caller_role' => 'app',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['message'])->toBe('This command may only be run from a control or gateway node.')
            ->and($payload['error']['meta'])->toBe(['caller_role' => 'app']);

        $mock->assertSent(fn (RemoveNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1');
    });
});
