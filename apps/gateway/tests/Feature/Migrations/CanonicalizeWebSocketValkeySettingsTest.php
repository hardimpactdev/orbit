<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function run_web_socket_valkey_settings_migration(): void
{
    $migration = require base_path('database/migrations/2026_07_17_000000_canonicalize_websocket_valkey_settings.php');

    $migration->up();
}

/**
 * Drop processes.label after fixture setup so the July migration can be
 * replayed against the pre-label schema it historically ran under.
 */
function restore_pre_process_label_schema_for_valkey_migration(): void
{
    if (! Schema::hasColumn('processes', 'label')) {
        return;
    }

    Schema::table('processes', static function (Blueprint $table): void {
        $table->dropColumn('label');
    });
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

it('canonicalizes redis to valkey on a pre-label processes schema without writing label', function (): void {
    $node = Node::factory()->create();
    $provider = Node::factory()
        ->database()
        ->create([
            'status' => 'active',
            'wireguard_address' => '10.6.0.43',
        ]);
    $process = Process::factory()
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

    restore_pre_process_label_schema_for_valkey_migration();

    expect(Schema::hasColumn('processes', 'label'))->toBeFalse();

    run_web_socket_valkey_settings_migration();

    expect(Schema::hasColumn('processes', 'label'))->toBeFalse();

    $row = DB::table('processes')->where('id', $process->id)->first();
    $freshAssignment = $assignment->fresh();

    expect($freshAssignment)->not->toBeNull();
    expect($freshAssignment?->settings)->toBe(['valkey_node_id' => $provider->id]);
    expect($row)->not->toBeNull();
    expect(property_exists($row, 'label'))->toBeFalse();
    expect($row->name)->toBe('valkey');
    expect(json_decode((string) $row->runtime_config, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'service' => 'valkey',
            'version' => '8.1',
            'image' => 'valkey/valkey:8.1',
            'replaces_runtime_unit' => 'redis',
        ]);
});
