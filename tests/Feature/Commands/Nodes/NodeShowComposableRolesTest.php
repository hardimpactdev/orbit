<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

describe('node:show composable roles', function (): void {
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

        $appDevelopmentRole = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'orbit.test'],
        ]);

        $databaseRole = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'host-1', '--json' => true]);
        $rawOutput = Artisan::output();
        $payload = json_decode($rawOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['role'])->toBe('app')
            ->and($payload['success']['data']['node']['environment'])->toBe('development')
            ->and($payload['success']['data']['node']['roles'])->toBe([
                [
                    'role' => 'app-development',
                    'status' => 'active',
                    'settings' => ['tld' => 'orbit.test'],
                    'last_error' => null,
                    'converged_at' => $appDevelopmentRole->converged_at?->toJSON(),
                ],
                [
                    'role' => 'database',
                    'status' => 'error',
                    'settings' => [],
                    'last_error' => null,
                    'converged_at' => $databaseRole->converged_at?->toJSON(),
                ],
            ])
            ->and($rawOutput)->toContain('"settings":{}')
            ->and($rawOutput)->toContain('"tld":"orbit.test"');
    });

    it('renders composable role assignments in the human output', function (): void {
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

        $exitCode = Artisan::call('node:show', ['name' => 'host-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Roles')
            ->and($output)->toContain('app-development, database (error)')
            ->and($output)->not->toMatch('/├  Role\s+app/')
            ->and($output)->not->toContain('Environment');
    });
});
