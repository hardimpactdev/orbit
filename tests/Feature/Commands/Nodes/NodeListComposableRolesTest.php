<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('node:list composable roles', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

        Node::factory()->create([
            'name' => 'local-gateway',
            'role' => 'gateway',
            'environment' => null,
            'host' => '10.6.0.1',
            'wireguard_address' => '10.6.0.1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
    });

    it('includes composable role assignments in JSON while preserving legacy fields', function (): void {
        $node = Node::factory()->create([
            'name' => 'host-1',
            'role' => 'app',
            'environment' => 'development',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'orbit.test'],
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $listedNode = collect($payload['success']['data']['nodes'])
            ->firstWhere('name', 'host-1');

        expect($listedNode)->toBe([
            'name' => 'host-1',
            'role' => 'app',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'roles' => [
                [
                    'role' => 'app-development',
                    'status' => 'active',
                    'settings' => ['tld' => 'orbit.test'],
                ],
                [
                    'role' => 'database',
                    'status' => 'error',
                    'settings' => [],
                ],
            ],
        ]);
    });

    it('renders composable role assignments in the human role column', function (): void {
        $node = Node::factory()->create([
            'name' => 'host-1',
            'role' => 'app',
            'environment' => 'development',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'orbit.test'],
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('app-development, database (error)')
            ->and($output)->toContain('host-1')
            ->and($output)->toContain('development');
    });
});
