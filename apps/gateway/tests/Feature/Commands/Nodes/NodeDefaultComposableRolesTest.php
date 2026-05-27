<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultComposableRolesRow(array $overrides = []): array
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

function createNodeDefaultComposableNode(array $overrides = []): Node
{
    return Node::query()->create(nodeDefaultComposableRolesRow($overrides));
}

function assignDefaultComposableRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

describe('node:default composable roles', function (): void {
    it('accepts only active app-development nodes as the local default', function (): void {
        $node = createNodeDefaultComposableNode();
        assignDefaultComposableRole($node, 'app-development', settings: ['tld' => 'test']);

        $exitCode = Artisan::call('node:default', [
            'name' => $node->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('set')
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBe($node->name);
    });

    it('rejects database-only nodes as the local default', function (): void {
        $node = createNodeDefaultComposableNode();
        assignDefaultComposableRole($node, 'database');

        $exitCode = Artisan::call('node:default', [
            'name' => $node->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.invalid_role');
    });

    it('rejects app-production nodes as the local default', function (): void {
        $node = createNodeDefaultComposableNode([
            'name' => 'prod-1',
            'environment' => 'production',
            'tld' => null,
        ]);
        assignDefaultComposableRole($node, 'app-production');

        $exitCode = Artisan::call('node:default', [
            'name' => $node->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.invalid_role');
    });
});
