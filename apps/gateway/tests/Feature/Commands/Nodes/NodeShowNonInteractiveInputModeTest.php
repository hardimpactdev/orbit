<?php

declare(strict_types=1);

use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowNonInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupShowNonInteractiveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeShowNonInteractiveRow([
        'name' => 'test-gateway',
        'role' => 'gateway',
        'environment' => null,
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function setupShowNonInteractiveControlCaller(): void
{
    config(['orbit.is_gateway' => false]);
}

function setupShowNonInteractiveAppCaller(): void
{
    config(['orbit.is_gateway' => false]);
}

function callShowNonInteractiveJsonCommand(string $command, array $parameters = []): array
{
    $exitCode = Artisan::call($command, [...$parameters, '--json' => true]);
    $output = Artisan::output();

    return [
        'exit_code' => $exitCode,
        'output' => $output,
        'payload' => json_decode($output, true, flags: JSON_THROW_ON_ERROR),
    ];
}

describe('node:show non-interactive input mode', function (): void {
    beforeEach(function (): void {
        setupShowNonInteractiveGatewayCaller();
    });

    it('uses non-interactive mode without TTY', function (): void {
        DB::table('nodes')->insert(nodeShowNonInteractiveRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('uses non-interactive mode when --json is present', function (): void {
        DB::table('nodes')->insert(nodeShowNonInteractiveRow());

        $result = callShowNonInteractiveJsonCommand('node:show', [
            'name' => 'app-1',
        ]);

        expect($result['exit_code'])->toBe(0);
        expect($result['payload'])->toBeArray();
        expect($result['payload'])->toHaveKey('success');
        expect($result['payload']['success'])->toBeArray();
        expect($result['payload']['success'])->toHaveKey('data');
    });

    it('resolves local default node when set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowNonInteractiveRow(['name' => 'default-app']));

        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'default-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = callShowNonInteractiveJsonCommand('node:show');

        expect($result['exit_code'])->toBe(0);
        expect($result['payload'])->toBeArray();
        expect($result['payload'])->toHaveKey('success');
        expect($result['payload']['success'])->toBeArray();
        expect($result['payload']['success'])->toHaveKey('data');
    });

    it('resolves calling node when no default is set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowNonInteractiveRow(['name' => 'other-gateway', 'role' => 'gateway']));

        $result = callShowNonInteractiveJsonCommand('node:show');

        expect($result['exit_code'])->toBe(0);
        expect($result['output'])->toContain('test-gateway');
    });

    it('fails with validation_failed when name is missing and no default can be resolved', function (): void {
        DB::table('nodes')->delete();
        DB::table('local_node_defaults')->delete();

        $result = callShowNonInteractiveJsonCommand('node:show');

        expect($result['exit_code'])->not->toBe(0);
        expect($result['payload']['error']['code'] ?? '')->toBe('validation_failed');
        expect($result['payload']['error']['meta']['field'] ?? '')->toBe('name');
    });

    it('fails when node does not exist', function (): void {
        $result = callShowNonInteractiveJsonCommand('node:show', [
            'name' => 'nonexistent',
        ]);

        expect($result['exit_code'])->not->toBe(0);
        expect($result['payload'])->toBeArray();
        expect($result['payload'])->toHaveKey('error');
        expect($result['payload'])->not->toHaveKey('success');
    });

    it('forwards for control callers when not on gateway', function (): void {
        setupShowNonInteractiveControlCaller();

        DB::table('nodes')->insert(nodeShowNonInteractiveRow(['name' => 'some-app']));

        $result = callShowNonInteractiveJsonCommand('node:show', [
            'name' => 'some-app',
        ]);

        expect($result['exit_code'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required');
    });
});
