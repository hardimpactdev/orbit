<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateHumanRow(array $overrides = []): array
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

function setupGatewayCallerHuman(): void
{
    DB::table('nodes')->insert(nodeUpdateHumanRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:update human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->doesntExpectOutputToContain('"success"')
            ->doesntExpectOutputToContain('"error"')
            ->assertSuccessful();
    });

    it('renders progress tree with tree characters', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain('┌ Updating Node')
            ->expectsOutputToContain('○ Validate node')
            ->expectsOutputToContain('○ Apply and verify node change')
            ->expectsOutputToContain('○ Apply node artifacts')
            ->expectsOutputToContain("└ Node 'app-1' updated")
            ->assertSuccessful();
    });

    it('renders drift prose when artifact re-enactment fails after intent update', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        app()->instance(ReenactNodeArtifacts::class, new class extends ReenactNodeArtifacts
        {
            public function handle(Node $node, array $changed): array
            {
                throw new RuntimeException('artifact failed');
            }
        });

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain("└ Node 'app-1' updated with drift")
            ->expectsOutputToContain('Drift detected: Node artifact re-enactment failed after intent update.')
            ->assertSuccessful();
    });

    it('renders success prose with changed fields', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain("Node 'app-1' updated")
            ->expectsOutputToContain('Changed: host')
            ->assertSuccessful();
    });

    it('renders no-op prose when no fields changed', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.7',
        ])
            ->expectsOutputToContain("└ Node 'app-1' unchanged")
            ->expectsOutputToContain("Node 'app-1' unchanged")
            ->expectsOutputToContain('No fields were modified.')
            ->assertSuccessful();
    });

    it('renders node-not-found prose error', function (): void {
        setupGatewayCallerHuman();

        $this->artisan('node:update', [
            'name' => 'missing-node',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain("Node 'missing-node' not found.")
            ->assertFailed();
    });

    it('renders no-fields-provided prose error', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('At least one field must be provided to update a node.')
            ->assertFailed();
    });

    it('renders field-role-incompatible prose error', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeUpdateHumanRow([
            'name' => 'target-gateway',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $this->artisan('node:update', [
            'name' => 'target-gateway',
            '--environment' => 'production',
        ])
            ->expectsOutputToContain("The field 'environment' is not valid for node 'target-gateway' (role: gateway).")
            ->assertFailed();
    });

    it('renders gateway-unavailable prose error for control caller', function (): void {
        DB::table('nodes')->insert(nodeUpdateHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain('Gateway connection is required to update a node.')
            ->assertFailed();
    });

    it('renders caller-role-not-allowed prose error for app caller', function (): void {
        DB::table('nodes')->insert(nodeUpdateHumanRow([
            'name' => 'test-app',
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain('This command may only be run from a control or gateway node.')
            ->assertFailed();
    });

    it('renders local-context-invalid prose error', function (): void {
        DB::table('nodes')->insert(nodeUpdateHumanRow([
            'name' => 'bogus-local',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
        ]));

        DB::table('nodes')->insert(nodeUpdateHumanRow());

        $this->artisan('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ])
            ->expectsOutputToContain('Local node role setting is invalid.')
            ->assertFailed();
    });
});
