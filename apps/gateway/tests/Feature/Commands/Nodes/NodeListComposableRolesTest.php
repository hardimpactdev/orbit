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

            'host' => '10.6.0.1',
            'wireguard_address' => '10.6.0.1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
    });

    it('includes composable role assignments in JSON while preserving legacy fields', function (): void {
        $node = Node::factory()->create([
            'name' => 'host-1',

            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);

        $appDevelopmentRole = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'orbit.test'],
        ]);

        $databaseRole = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $rawOutput = Artisan::output();
        $payload = json_decode($rawOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $listedNode = collect($payload['success']['data']['nodes'])
            ->firstWhere('name', 'host-1');

        expect($listedNode)->toBe([
            'name' => 'host-1',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'roles' => [
                [
                    'status' => 'active',
                    'settings' => ['tld' => 'orbit.test'],
                    'last_error' => null,
                    'converged_at' => $appDevelopmentRole->converged_at?->toJSON(),
                ],
                [
                    'status' => 'error',
                    'settings' => [],
                    'last_error' => null,
                    'converged_at' => $databaseRole->converged_at?->toJSON(),
                ],
            ],
        ])
            ->and($rawOutput)->toContain('"settings":{}')
            ->and($rawOutput)->toContain('"tld":"orbit.test"');
    });

    it('renders legacy role and composable roles in separate human columns', function (): void {
        $node = Node::factory()->create([
            'name' => 'host-1',

            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
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
            ->and($output)->toContain('ROLE')
            ->and($output)->toContain('ROLES')
            ->and($output)->toContain('app')
            ->and($output)->toContain('app-development, database (error)')
            ->and($output)->toContain('host-1')
            ->and($output)->toContain('development');
    });
});
