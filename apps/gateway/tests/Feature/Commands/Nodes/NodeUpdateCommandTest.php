<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Http\Gateway\Requests\Nodes\UpdateNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateBaseRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupGatewayCallerBase(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeUpdateBaseRow([
        'name' => 'gateway-1',
    ]));
}

function setupControlCallerForNodeUpdate(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeUpdateGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        UpdateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:update base contract', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('updates a node and returns successfully', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ]);

        expect($exitCode)->toBe(0);
    });

    it('returns success with empty changed array for no-op update', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBeEmpty()
            ->and($payload['success']['data']['action'])->toBe('updated');
    });

    it('updates multiple fields and reports all in changed array', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--environment' => 'production',
            '--public-ipv4' => '203.0.113.50',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toHaveCount(3)
            ->and($payload['success']['data']['changed'])->toContain('host')
            ->and($payload['success']['data']['changed'])->toContain('environment')
            ->and($payload['success']['data']['changed'])->toContain('public_ipv4');
    });

    it('persists changes to the database', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--environment' => 'production',
            '--json' => true,
        ]);

        $node = DB::table('nodes')->where('name', 'app-1')->first();

        expect($node->host)->toBe('10.6.0.99')
            ->and($node->environment)->toBe('production');
    });

    it('fails with node.not_found for missing target', function (): void {
        $exitCode = Artisan::call('node:update', [
            'name' => 'nonexistent',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });

    it('fails with validation_failed when no field flags provided', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('fields');
    });

    it('fails with validation_failed when name is missing', function (): void {
        $exitCode = Artisan::call('node:update', [
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });
});

describe('node:update caller role behavior', function (): void {

    it('rejects unconfigured control-node callers with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodeUpdateBaseRow([
            'name' => 'control-1',
        ]));

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });
});

describe('node:update control forwarding', function (): void {
    it('forwards configured control-node updates to the gateway without a local target row', function (): void {
        config(['orbit.is_gateway' => false]);

        setupControlCallerForNodeUpdate();

        $mock = fakeNodeUpdateGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'changed' => ['host', 'public_ipv4'],
                    'action' => 'updated',
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.8',
            '--public-ipv4' => '203.0.113.10',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'name' => 'app-1',
                'changed' => ['host', 'public_ipv4'],
                'action' => 'updated',
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();

        $mock->assertSent(fn (UpdateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1'
            && $request->body()->all() === [
                'host' => '10.6.0.8',
                'public_ipv4' => '203.0.113.10',
            ]);
    });

    it('preserves forwarded artifact enactment warnings from the gateway', function (): void {
        setupControlCallerForNodeUpdate();

        fakeNodeUpdateGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'changed' => ['host'],
                    'action' => 'updated',
                ],
                'meta' => [
                    'warnings' => [[
                        'code' => 'node.artifact_enactment_failed',
                        'message' => 'Node artifact re-enactment failed after intent update.',
                        'family' => 'node',
                        'next_command' => 'doctor --family=node --restore',
                    ]],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.8',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toBe([[
                'code' => 'node.artifact_enactment_failed',
                'message' => 'Node artifact re-enactment failed after intent update.',
                'family' => 'node',
                'next_command' => 'doctor --family=node --restore',
            ]]);
    });

    it('passes through structured gateway authorization failures', function (): void {
        config(['orbit.is_gateway' => false]);

        setupControlCallerForNodeUpdate();

        fakeNodeUpdateGateway([
            'error' => [
                'code' => 'authorization_failed',
                'message' => "This node is not authorized for 'node:update' on 'app-1'.",
                'meta' => [
                    'reason' => 'missing_permission',
                    'missing_permission' => 'node:update',
                    'serving_node' => 'app-1',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.8',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['message'])->toBe("This node is not authorized for 'node:update' on 'app-1'.")
            ->and($payload['error']['meta'])->toBe([
                'reason' => 'missing_permission',
                'missing_permission' => 'node:update',
                'serving_node' => 'app-1',
            ]);
    });

    it('validates control-node input locally before forwarding', function (): void {
        setupControlCallerForNodeUpdate();

        $mock = fakeNodeUpdateGateway([
            'success' => ['data' => ['name' => 'app-1']],
        ]);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--environment' => 'staging',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('environment');

        $mock->assertNothingSent();
    });
});

describe('node:update field value validation', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('rejects empty host value', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('host');
    });

    it('rejects invalid environment value', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--environment' => 'staging',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('environment')
            ->and($payload['error']['meta']['value'])->toBe('staging')
            ->and($payload['error']['meta']['allowed'])->toBe(['development', 'production']);
    });

    it('rejects invalid public-ipv4 format', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv4' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('public_ipv4');
    });

    it('rejects invalid public-ipv6 format', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv6' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('public_ipv6');
    });

    it('accepts valid ipv6 address', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--public-ipv6' => '2001:db8::1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['public_ipv6']);
    });

    it('sets tld on a development app node and reports it as changed', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow(['tld' => null]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['tld'])
            ->and(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('rejects an invalid tld value', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'Invalid_TLD!',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['value'])->toBe('Invalid_TLD!');
    });

    it('rejects tld on a production app node', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow([]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.field_role_incompatible')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('rejects tld already assigned to another active node', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow(['tld' => null]));
        DB::table('nodes')->insert(nodeUpdateBaseRow([
            'name' => 'app-2',
            'tld' => 'test',
        ]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.tld_in_use')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['value'])->toBe('test');
    });
});

describe('node:update safety', function (): void {
    beforeEach(function (): void {
        setupGatewayCallerBase();
    });

    it('does not invoke ssh or external processes during update', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        Process::assertNothingRan();
    });

    it('returns success with a node artifact warning when re-enactment fails after intent update', function (): void {
        app()->instance(ReenactNodeArtifacts::class, new class extends ReenactNodeArtifacts
        {
            public function handle(Node $node, array $changed): array
            {
                throw new RuntimeException('artifact failed');
            }
        });

        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.99')
            ->and($payload['success']['data']['changed'])->toBe(['host'])
            ->and($payload['success']['meta']['warnings'])->toBe([[
                'code' => 'node.artifact_enactment_failed',
                'message' => 'Node artifact re-enactment failed after intent update.',
                'family' => 'node',
                'next_command' => 'doctor --family=node --restore',
            ]]);
    });

    it('makes only targeted registry mutations', function (): void {
        DB::table('nodes')->insert(nodeUpdateBaseRow());

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after['host'])->toBe('10.6.0.99')
            ->and($after['environment'])->toBe($before['environment'])
            ->and($after['role'])->toBe($before['role'])
            ->and($after['status'])->toBe($before['status']);
    });
});
