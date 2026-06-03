<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates node tool instance runtime columns with expected broad types', function (): void {
    $expectedColumns = [
        'id',
        'node_id',
        'name',
        'instance_key',
        'version_family',
        'runtime',
        'runtime_config',
        'expected_state',
        'expected_version',
        'config',
        'credentials',
        'created_at',
        'updated_at',
    ];

    $missingColumns = array_values(array_filter(
        $expectedColumns,
        fn (string $column): bool => ! Schema::hasColumn('node_tools', $column),
    ));

    expect(Schema::hasTable('node_tools'))->toBeTrue()
        ->and($missingColumns)->toBe(
            [],
            'node_tools missing instance/runtime columns: '.implode(', ', $missingColumns),
        )
        ->and(Schema::getColumnType('node_tools', 'instance_key'))->toBeIn(['string', 'varchar'])
        ->and(Schema::getColumnType('node_tools', 'version_family'))->toBeIn(['string', 'varchar'])
        ->and(Schema::getColumnType('node_tools', 'runtime'))->toBeIn(['string', 'varchar'])
        ->and(Schema::getColumnType('node_tools', 'runtime_config'))->toBeIn(['json', 'text']);
});

it('backfills existing tool rows to the default instance and runtime', function (): void {
    $node = Node::factory()->create();

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);

    $tool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->firstOrFail();

    $missingAttributes = array_values(array_filter(
        ['instance_key', 'version_family', 'runtime', 'runtime_config'],
        fn (string $attribute): bool => ! array_key_exists($attribute, $tool->getAttributes()),
    ));

    expect($missingAttributes)->toBe(
        [],
        'backfilled node_tools row is missing attributes: '.implode(', ', $missingAttributes),
    )
        ->and($tool->instance_key)->toBe('redis:default')
        ->and($tool->version_family)->toBeNull()
        ->and($tool->runtime)->toBe('docker')
        ->and($tool->runtime_config)->toBeNull();
});

it('keys node tool uniqueness by node name and instance key', function (): void {
    $uniqueIndexes = nodeToolUniqueIndexColumns();

    expect(in_array(['node_id', 'name', 'instance_key'], $uniqueIndexes, true))->toBe(
        true,
        'node_tools unique indexes must include (node_id, name, instance_key); actual: '.json_encode($uniqueIndexes, JSON_THROW_ON_ERROR),
    )
        ->and(in_array(['node_id', 'name'], $uniqueIndexes, true))->toBe(
            false,
            'node_tools must drop the old (node_id, name) unique index once instance_key is part of identity.',
        );
});

it('allows multiple version-family tool instances on one node', function (): void {
    $node = Node::factory()->create();

    insertNodeToolInstance(
        node: $node,
        name: 'mysql',
        instanceKey: 'mysql:8',
        versionFamily: '8',
        runtime: 'docker',
    );
    insertNodeToolInstance(
        node: $node,
        name: 'mysql',
        instanceKey: 'mysql:9',
        versionFamily: '9',
        runtime: 'docker-swarm',
    );

    expect(DB::table('node_tools')
        ->where('node_id', $node->id)
        ->where('name', 'mysql')
        ->count())->toBe(2);

    expect(fn () => insertNodeToolInstance(
        node: $node,
        name: 'mysql',
        instanceKey: 'mysql:8',
        versionFamily: '8',
        runtime: 'docker',
    ))->toThrow(QueryException::class);
});

it('casts runtime config without regressing encrypted credentials', function (): void {
    $node = Node::factory()->create();

    $tool = NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
        'runtime_config' => [
            'service' => 'redis',
            'volume' => 'orbit-redis-default',
        ],
        'credentials' => [
            'password' => 'secret',
        ],
    ]);

    expect($tool->fresh())
        ->runtime_config->toBe([
            'service' => 'redis',
            'volume' => 'orbit-redis-default',
        ])
        ->credentials->toBe([
            'password' => 'secret',
        ]);

    $storedCredentials = (string) DB::table('node_tools')->whereKey($tool->id)->value('credentials');

    expect(str_contains($storedCredentials, 'secret'))->toBeFalse();
});

it('keeps default instance tools compatible with name-only callers', function (): void {
    $node = Node::factory()->create();

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);

    $updatedTool = NodeTool::query()->updateOrCreate(
        [
            'node_id' => $node->id,
            'name' => 'redis',
        ],
        [
            'expected_state' => 'installed',
        ],
    );

    $updatedTool = $updatedTool->fresh();

    expect(NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->count())->toBe(1)
        ->and($updatedTool->instance_key)->toBe(
            'redis:default',
            'name-only default-instance tools should still resolve to redis:default.',
        )
        ->and($updatedTool->runtime)->toBe('docker')
        ->and($updatedTool->expected_state)->toBe('installed');
});

/**
 * @return list<list<string>>
 */
function nodeToolUniqueIndexColumns(): array
{
    return array_values(array_map(
        function (object $index): array {
            $indexName = str_replace("'", "''", (string) $index->name);

            return array_map(
                fn (object $column): string => (string) $column->name,
                DB::select("PRAGMA index_info('{$indexName}')"),
            );
        },
        array_filter(
            DB::select('PRAGMA index_list(node_tools)'),
            fn (object $index): bool => (int) $index->unique === 1,
        ),
    ));
}

function insertNodeToolInstance(
    Node $node,
    string $name,
    string $instanceKey,
    ?string $versionFamily,
    string $runtime,
): void {
    DB::table('node_tools')->insert([
        'node_id' => $node->id,
        'name' => $name,
        'instance_key' => $instanceKey,
        'version_family' => $versionFamily,
        'runtime' => $runtime,
        'runtime_config' => null,
        'expected_state' => 'running',
        'expected_version' => $versionFamily,
        'config' => json_encode(['endpoints' => []], JSON_THROW_ON_ERROR),
        'credentials' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
