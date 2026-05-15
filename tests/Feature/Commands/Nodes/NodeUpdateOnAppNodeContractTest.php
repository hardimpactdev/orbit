<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\UpdateNodeRequest;
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
function nodeUpdateAppContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-caller',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeUpdateAppContractCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeUpdateAppContractRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeUpdateAppContractGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        UpdateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:update on app node contract', function (): void {
    it('forwards app-role CLI callers to the gateway instead of pre-rejecting locally', function (): void {
        setupNodeUpdateAppContractCaller();

        $mock = fakeNodeUpdateAppContractGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control or gateway node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();

        $mock->assertSent(fn (UpdateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1'
            && $request->body()->all() === [
                'tld' => 'test',
            ]);
    });

    it('preserves gateway caller_role_not_allowed JSON errors for app-role CLI callers', function (): void {
        setupNodeUpdateAppContractCaller();

        $error = [
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from a control or gateway node.',
            'meta' => [
                'caller_role' => 'app',
            ],
        ];

        fakeNodeUpdateAppContractGateway(['error' => $error], 403);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    });

    it('preserves gateway caller_role_not_allowed human errors for app-role CLI callers', function (): void {
        setupNodeUpdateAppContractCaller();

        fakeNodeUpdateAppContractGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control or gateway node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
        ]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('This command may only be run from a control or gateway node.');
    });
});
