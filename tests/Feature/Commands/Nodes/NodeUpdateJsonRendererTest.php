<?php

declare(strict_types=1);

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
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupGatewayCallerJson(): void
{
    DB::table('nodes')->insert(nodeUpdateJsonRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:update JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

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
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:update', [
            'name' => 'target-gateway',
            '--environment' => 'production',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.field_role_incompatible')
            ->and($payload['error']['meta']['field'])->toBe('environment')
            ->and($payload['error']['meta']['name'])->toBe('target-gateway')
            ->and($payload['error']['meta']['role'])->toBe('gateway');
    });

    it('returns caller_role_not_allowed error for app-node callers', function (): void {
        DB::table('nodes')->insert(nodeUpdateJsonRow([
            'name' => 'test-app',
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateJsonRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['message'])->toBe('This command may only be run from a control or gateway node.')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('returns gateway_unavailable error for control-node callers', function (): void {
        DB::table('nodes')->insert(nodeUpdateJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
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

    it('returns local_context_invalid error with correct metadata', function (): void {
        DB::table('nodes')->insert(nodeUpdateJsonRow([
            'name' => 'bogus-local',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateJsonRow());

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['message'])->toBe('Local node role setting is invalid.')
            ->and($payload['error']['meta'])->toBe([
                'setting' => 'general.local_node_role',
                'reason' => 'unsupported_value',
                'caller_role' => 'unknown',
            ]);
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

    it('returns validation_failed error for invalid environment', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

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

    it('returns validation_failed error for invalid public-ipv4', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeUpdateJsonRow());

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
