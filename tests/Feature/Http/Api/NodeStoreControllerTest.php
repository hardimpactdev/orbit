<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiStoreNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'tld' => null,
        'platform' => 'unknown',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => null,
        'ssh_user' => 'orbit',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'is_local' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('NodeStoreController', function (): void {
    it('provisions an app node for an authenticated control caller', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'control-1',
                'role' => 'control',
                'host' => '10.6.0.3',
                'wireguard_address' => '10.6.0.3',
                'gateway_endpoint' => '10.6.0.2',
                'ssh_user' => 'tester',
                'user' => 'tester',
                'orbit_path' => '/home/tester/orbit',
                'is_local' => false,
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
                'ssh_user' => 'provisioner',
            ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.name', 'app-dev-1')
            ->assertJsonPath('success.data.node.role', 'app')
            ->assertJsonPath('success.data.development_tld.gateway_dns.domain', '*.test');

        $node = DB::table('nodes')->where('name', 'app-dev-1')->first();

        expect($node)->not->toBeNull()
            ->and($node->environment)->toBe('development')
            ->and($node->tld)->toBe('test')
            ->and($node->wireguard_address)->toBe('10.6.0.4');

        $entry = Activity::query()
            ->where('event', 'node.created')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->subject?->name)->toBe('app-dev-1');
        expect($entry->properties->get('name'))->toBe('app-dev-1');
        expect($entry->properties->get('role'))->toBe('app');
        expect($entry->properties->get('environment'))->toBe('development');
        expect($entry->properties->get('tld'))->toBe('test');

        Process::assertRan(fn ($process): bool => str_contains($process->command, '--role=')
            && str_contains($process->command, 'app')
            && str_contains($process->command, '--source-archive='));
    });

    it('rejects app callers before provisioning', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow(),
            apiStoreNodeRow([
                'name' => 'app-caller',
                'role' => 'app',
                'environment' => 'development',
                'tld' => 'caller',
                'host' => '10.6.0.7',
                'wireguard_address' => '10.6.0.7',
                'gateway_endpoint' => '10.6.0.2',
                'is_local' => false,
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.7'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'role' => 'app',
                'host' => '192.0.2.20',
                'environment' => 'development',
                'tld' => 'test',
                'ssh_user' => 'provisioner',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });
});
