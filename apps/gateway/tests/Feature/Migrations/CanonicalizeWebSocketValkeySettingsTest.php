<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function run_web_socket_valkey_settings_migration(): void
{
    $migration = require base_path('database/migrations/2026_07_17_000000_canonicalize_websocket_valkey_settings.php');

    $migration->up();
}

it('canonicalizes legacy websocket Redis settings to Valkey settings', function (): void {
    $node = Node::factory()->create();
    $provider = Node::factory()
        ->database()
        ->create([
            'status' => 'active',
            'wireguard_address' => '10.6.0.42',
        ]);
    Process::factory()
        ->forOwner($provider)
        ->create([
            'name' => 'redis',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'redis-server --appendonly yes',
            'runtime_config' => ['service' => 'redis'],
        ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => $provider->id],
    ]);

    run_web_socket_valkey_settings_migration();

    expect($assignment->fresh()->settings)
        ->toBe(['valkey_node_id' => $provider->id])
        ->and(Process::query()->ownedBy($provider)->get())
        ->toHaveCount(1)
        ->and(Process::query()->ownedBy($provider)->sole())
        ->name->toBe('valkey')
        ->runtime_config->toMatchArray([
            'service' => 'valkey',
            'version' => '8.1',
            'image' => 'valkey/valkey:8.1',
            'replaces_runtime_unit' => 'redis',
        ]);
});

it('does not migrate a websocket selection without a convertible Valkey provider', function (): void {
    $node = Node::factory()->create();
    $provider = Node::factory()->database()->create();
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => $provider->id],
    ]);

    expect(run_web_socket_valkey_settings_migration(...))
        ->toThrow(
            RuntimeException::class,
            "Websocket role assignment [{$assignment->id}] references node [{$provider->id}] without a managed Redis or Valkey process.",
        );

    expect($assignment->fresh()->settings)->toBe(['redis_node_id' => $provider->id]);
});

it('removes duplicate legacy Redis intent when the provider already has Valkey', function (): void {
    $node = Node::factory()->create();
    $provider = Node::factory()
        ->database()
        ->create([
            'status' => 'active',
            'wireguard_address' => '10.6.0.42',
        ]);
    Process::factory()
        ->forOwner($provider)
        ->create([
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => ['service' => 'valkey'],
        ]);
    Process::factory()
        ->forOwner($provider)
        ->create([
            'name' => 'redis',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => ['service' => 'redis'],
        ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => $provider->id],
    ]);

    run_web_socket_valkey_settings_migration();

    expect($assignment->fresh()->settings)
        ->toBe(['valkey_node_id' => $provider->id])
        ->and(Process::query()->ownedBy($provider)->get())
        ->toHaveCount(1)
        ->and(Process::query()->ownedBy($provider)->sole()->runtime_config)
        ->toMatchArray([
            'service' => 'valkey',
            'replaces_runtime_unit' => 'redis',
        ]);
});

it('rejects conflicting legacy and canonical websocket broker settings', function (): void {
    $node = Node::factory()->create();
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => 42],
    ]);

    DB::table('node_role')
        ->where('id', $assignment->id)
        ->update([
            'settings' => json_encode([
                'redis_node_id' => 42,
                'valkey_node_id' => 43,
            ], JSON_THROW_ON_ERROR),
        ]);

    expect(run_web_socket_valkey_settings_migration(...))
        ->toThrow(
            RuntimeException::class,
            "Websocket role assignment [{$assignment->id}] has conflicting Redis and Valkey node settings.",
        );
});
