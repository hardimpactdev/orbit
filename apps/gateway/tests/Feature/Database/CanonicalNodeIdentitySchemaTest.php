<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('requires a valid unique TLD for every active node', function (): void {
    expect(fn () => DB::table('nodes')->insert(canonical_node_identity_row('missing', null)))
        ->toThrow(QueryException::class, 'active nodes require a valid TLD');

    expect(fn () => DB::table('nodes')->insert(canonical_node_identity_row('reserved', 'orbit')))
        ->toThrow(QueryException::class, 'active nodes require a valid TLD');

    expect(fn () => DB::table('nodes')->insert(canonical_node_identity_row('whitespace', ' fleet ')))
        ->toThrow(QueryException::class, 'active nodes require a valid TLD');

    DB::table('nodes')->insert(canonical_node_identity_row('first', 'fleet'));

    expect(fn () => DB::table('nodes')->where('name', 'first')->update(['tld' => 'orbit']))
        ->toThrow(QueryException::class, 'active nodes require a valid TLD');

    expect(fn () => DB::table('nodes')->insert(canonical_node_identity_row('second', 'fleet')))
        ->toThrow(QueryException::class, 'UNIQUE constraint failed: nodes.tld');

    DB::table('nodes')->insert(canonical_node_identity_row('inactive', null, 'inactive'));

    expect(DB::table('nodes')->where('name', 'inactive')->value('tld'))->toBeNull();
});

it('refuses to reserve the private namespace while an active node still owns it', function (): void {
    canonical_node_identity_drop_guards();
    DB::table('nodes')->insert(canonical_node_identity_row('legacy-reserved', 'orbit'));

    expect(run_reserve_private_dns_namespace_migration(...))
        ->toThrow(RuntimeException::class, 'reserved_node_tld_conflict: active node(s) [legacy-reserved]');

    expect(DB::table('nodes')->where('name', 'legacy-reserved')->value('tld'))->toBe('orbit');
});

it('backfills canonical identity and managed operator intent idempotently', function (): void {
    canonical_node_identity_drop_guards();

    Schema::table('nodes', static function (Blueprint $table): void {
        $table->boolean('orbit_agent_capable')->default(false);
    });

    $operatorId = (int) DB::table('nodes')->insertGetId([
        ...canonical_node_identity_row('mini', null),
        'platform' => 'darwin',
        'wireguard_address' => '10.6.0.8',
        'orbit_agent_capable' => true,
    ]);
    $workloadId = (int) DB::table('nodes')->insertGetId([
        ...canonical_node_identity_row('app-dev-1', 'test'),
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.9',
        'orbit_agent_capable' => true,
    ]);
    DB::table('node_role')->insert([
        'node_id' => $workloadId,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    run_canonical_node_identity_migration();
    run_canonical_node_identity_migration();

    expect(DB::table('nodes')->where('id', $operatorId)->value('tld'))
        ->toBe('mini')
        ->and(canonical_node_managed($operatorId))
        ->toBeTrue()
        ->and(canonical_node_managed($workloadId))
        ->toBeFalse()
        ->and(json_decode((string) DB::table('node_role')->where('node_id', $workloadId)->value('settings'), true))
        ->toBe([])
        ->and(Schema::hasColumn('nodes', 'orbit_agent_capable'))
        ->toBeFalse();
});

it('reports node and role-assignment TLD conflicts before mutating identity', function (): void {
    canonical_node_identity_drop_guards();

    $nodeId = (int) DB::table('nodes')->insertGetId(canonical_node_identity_row('app-dev-1', 'test'));
    $assignmentId = (int) DB::table('node_role')->insertGetId([
        'node_id' => $nodeId,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => json_encode(['tld' => 'other'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(run_canonical_node_identity_migration(...))
        ->toThrow(RuntimeException::class, 'node_assignment_tld_conflict');

    expect(DB::table('nodes')->where('id', $nodeId)->value('tld'))->toBe('test');

    DB::table('node_role')
        ->where('id', $assignmentId)
        ->update([
            'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
        ]);
    run_canonical_node_identity_migration();
});

/** @return array<string, mixed> */
function canonical_node_identity_row(string $name, ?string $tld, string $status = 'active'): array
{
    static $octet = 10;
    $octet++;

    return [
        'name' => $name,
        'tld' => $tld,
        'platform' => 'ubuntu_24-04',
        'host' => "192.0.2.{$octet}",
        'wireguard_address' => "10.6.0.{$octet}",
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function canonical_node_identity_drop_guards(): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS nodes_active_tld_required_insert');
    DB::unprepared('DROP TRIGGER IF EXISTS nodes_active_tld_required_update');
    DB::statement('DROP INDEX IF EXISTS nodes_active_tld_unique');
}

function run_canonical_node_identity_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_07_12_085630_canonicalize_node_identity_and_managed_agent_intent.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Canonical node identity migration must expose up().');
    }

    $migration->up();
}

function run_reserve_private_dns_namespace_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_07_19_080101_reserve_private_dns_namespace_for_node_tlds.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Reserved private DNS namespace migration must expose up().');
    }

    $migration->up();
}

function canonical_node_managed(int $nodeId): bool
{
    return match (DB::table('nodes')->where('id', $nodeId)->value('managed')) {
        true, 1 => true,
        false, 0 => false,
        default => throw new RuntimeException("Node {$nodeId} has an invalid managed value."),
    };
}
