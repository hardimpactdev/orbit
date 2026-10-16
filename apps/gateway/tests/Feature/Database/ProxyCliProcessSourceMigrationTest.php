<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyCliProcessSource;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves populated source state reversibly without changing Processes or their dependents', function (): void {
    $migration = require database_path('migrations/2026_10_16_074137_move_proxycli_process_sources_to_extension_table.php');
    $migration->down();
    $node = Node::query()->create(['name' => 'services', 'status' => 'active', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8']);
    $sources = [
        103 => ['cli-proxy-api', str_repeat('a', 64), false],
        163 => ['cli-proxy-api-collector', str_repeat('b', 64), true],
        164 => ['unrelated', null, false],
        165 => ['pending-without-revision', null, true],
        166 => ['empty-revision', '', false],
    ];
    foreach ($sources as $id => [$name, $revision, $pending]) {
        DB::table('processes')->insert([
            'id' => $id, 'owner_type' => Node::class, 'owner_id' => $node->id,
            'name' => $name, 'runtime' => 'systemd', 'working_directory' => '/srv/example',
            'runtime_config' => '{"command":"/bin/true"}', 'status' => 'active',
            'systemd_activation_hash' => str_repeat('c', 64), 'systemd_activation_pending' => false,
            'proxycli_source_revision' => $revision, 'proxycli_source_activation_pending' => $pending,
        ]);
    }
    Schema::create('source_migration_dependents', static function (Blueprint $table): void {
        $table->foreignId('process_id')->constrained('processes')->cascadeOnDelete();
        $table->string('payload');
    });
    DB::table('source_migration_dependents')->insert(['process_id' => 103, 'payload' => 'must survive']);
    DB::statement('CREATE TABLE source_migration_audit (process_id INTEGER)');
    DB::statement('CREATE TRIGGER source_migration_delete AFTER DELETE ON processes BEGIN INSERT INTO source_migration_audit VALUES (OLD.id); END');
    DB::statement("UPDATE sqlite_sequence SET seq = 999 WHERE name = 'processes'");
    $before = DB::table('processes')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    $foreignKeys = DB::select('PRAGMA foreign_key_list(source_migration_dependents)');
    $triggers = DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get()->all();

    $migration->up();

    expect(Schema::hasColumn('processes', 'proxycli_source_revision'))->toBeFalse()
        ->and(Schema::hasColumn('processes', 'proxycli_source_activation_pending'))->toBeFalse()
        ->and(ProxyCliProcessSource::query()->count())->toBe(5);
    foreach ($sources as $id => [$name, $revision, $pending]) {
        $source = ProxyCliProcessSource::query()->findOrFail($id);
        expect($source->source_revision)->toBe($revision)
            ->and($source->activation_pending)->toBe($pending);
    }
    $withoutSources = array_map(static function (array $row): array {
        unset($row['proxycli_source_revision'], $row['proxycli_source_activation_pending']);

        return $row;
    }, $before);
    expect(DB::table('processes')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($withoutSources)
        ->and(DB::table('source_migration_dependents')->value('payload'))->toBe('must survive')
        ->and(DB::table('source_migration_audit')->count())->toBe(0)
        ->and(DB::select('PRAGMA foreign_key_list(source_migration_dependents)'))->toEqual($foreignKeys)
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get()->all())->toEqual($triggers)
        ->and(DB::table('sqlite_sequence')->where('name', 'processes')->value('seq'))->toBe(999)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBeEmpty();

    $migration->down();

    expect(Schema::hasTable('ai_router_process_sources'))->toBeFalse()
        ->and(DB::table('processes')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all())->toEqual($before)
        ->and(DB::table('source_migration_dependents')->value('payload'))->toBe('must survive')
        ->and(DB::table('source_migration_audit')->count())->toBe(0)
        ->and(DB::table('sqlite_sequence')->where('name', 'processes')->value('seq'))->toBe(999);

    $migration->up();
    Process::query()->findOrFail(163)->delete();
    expect(ProxyCliProcessSource::query()->find(163))->toBeNull()
        ->and(ProxyCliProcessSource::query()->find(103))->not->toBeNull();
});

it('rolls back the copy and first column drop when the second column drop is refused', function (): void {
    $migration = require database_path('migrations/2026_10_16_074137_move_proxycli_process_sources_to_extension_table.php');
    $migration->down();
    DB::table('processes')->insert([
        'id' => 163, 'owner_type' => Node::class, 'owner_id' => 1,
        'name' => 'cli-proxy-api-collector', 'runtime' => 'systemd',
        'working_directory' => '/srv/example', 'runtime_config' => '{}',
        'proxycli_source_revision' => str_repeat('d', 64), 'proxycli_source_activation_pending' => true,
    ]);
    DB::statement('CREATE INDEX source_migration_blocker ON processes (proxycli_source_activation_pending)');

    expect(fn () => $migration->up())->toThrow(QueryException::class);

    expect(Schema::hasTable('ai_router_process_sources'))->toBeFalse()
        ->and(DB::table('processes')->where('id', 163)->value('proxycli_source_revision'))->toBe(str_repeat('d', 64))
        ->and(DB::table('processes')->where('id', 163)->value('proxycli_source_activation_pending'))->toBe(1);
    DB::statement('DROP INDEX source_migration_blocker');
    $migration->up();
});
