<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateJsonRow(array $overrides = []): array
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

function setupGatewayCallerJson(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeUpdateJsonRow([
        'name' => 'gateway-1',
    ]));
    assignNodeUpdateJsonRole('gateway-1', 'gateway');
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignNodeUpdateJsonRole(string $nodeName, string $role, array $settings = []): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('node:update JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success data with name, changed array, and action fields', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $data = $payload['success']['data'];

        expect($data)->toHaveKey('name')
            ->and($data['name'])->toBe('app-1')
            ->and($data)->toHaveKey('changed')
            ->and($data['changed'])->toBe(['host'])
            ->and($data)->toHaveKey('action')
            ->and($data['action'])->toBe('updated');
    });

    it('returns empty changed array for no-op update', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

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

    it('returns success meta warnings when artifact re-enactment fails after update', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

        app()->instance(ReenactNodeArtifacts::class, new class extends ReenactNodeArtifacts
        {
            public function handle(Node $node, array $changed): array
            {
                throw new RuntimeException('artifact failed');
            }
        });

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
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

    it('returns node.not_found error with correct metadata', function (): void {
        setupGatewayCallerJson();

        $exitCode = Artisan::call('node:update', [
            'name' => 'nonexistent',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Node 'nonexistent' not found.")
            ->and($error['meta'])->toBe(['name' => 'nonexistent']);
    });

    it('returns validation_failed error when no fields provided', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('At least one field must be provided to update a node.')
            ->and($payload['error']['meta']['field'])->toBe('fields');
    });

    it('returns validation_failed error when name is missing', function (): void {
        setupGatewayCallerJson();

        $exitCode = Artisan::call('node:update', [
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Node name is required.')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });

    it('returns node.field_role_incompatible error with metadata', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow([
            'name' => 'target-gateway',
        ]));
        assignNodeUpdateJsonRole('target-gateway', 'gateway');

        $exitCode = Artisan::call('node:update', [
            'name' => 'target-gateway',
            '--tld' => 'test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.field_role_incompatible')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['name'])->toBe('target-gateway')
            ->and($payload['error']['meta']['role'])->toBe('gateway');
    });

    it('returns gateway_unavailable error for control-node callers', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->insert(nodeUpdateJsonRow([
            'name' => 'control-1',
        ]));

        DB::table('nodes')->insert(nodeUpdateJsonRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable')
            ->and($payload['error']['message'])->toBe('Gateway connection is required to update a node.')
            ->and($payload['error']['meta'])->toBe([]);
    });

    it('returns validation_failed error for duplicate field flag', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

        $kernel = app(Kernel::class);
        $input = new ArgvInput(['artisan', 'node:update', 'app-1', '--host=10.6.0.99', '--host=10.6.0.100', '--json']);
        $output = new BufferedOutput;

        $exitCode = $kernel->handle($input, $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, associative: true);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe("Field 'host' was supplied more than once.")
            ->and($payload['error']['meta']['field'])->toBe('host');
    });

    it('returns validation_failed error for empty field value', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

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

    it('returns validation_failed error for invalid tld', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

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

    it('returns validation_failed error for invalid public-ipv4', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

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

    it('returns validation_failed error for invalid public-ipv6', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

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

    it('documents success.meta.warnings[] shape even when not triggered', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success'])->toHaveKey('data')
            ->and($payload['success'])->not->toHaveKey('meta');
    });

    it('uses correct enum values for action and error codes', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());
        assignNodeUpdateJsonRole('app-1', 'app-dev');

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('updated');
    });
});
