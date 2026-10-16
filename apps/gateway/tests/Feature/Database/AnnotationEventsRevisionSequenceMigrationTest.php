<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function annotation_events_sequence_migration(): object
{
    return require base_path('database/migrations/2026_11_12_000000_drop_payload_and_instance_from_annotation_events.php');
}

function annotation_events_counter(): int
{
    return (int) DB::table('sqlite_sequence')->where('name', 'annotation_events')->value('seq');
}

function annotation_events_index_exists(): bool
{
    return DB::table('sqlite_master')->where('name', 'annotation_events_instance_id_id_index')->exists();
}

it('leaves annotation_events as a revision sequence', function (): void {
    expect(Schema::getColumnListing('annotation_events'))->toBe(['id', 'created_at', 'updated_at'])
        ->and(annotation_events_index_exists())->toBeFalse()
        ->and(Schema::getForeignKeys('annotation_events'))->toBe([]);
});

it('keeps every revision and the counter through the drop and its rollback', function (): void {
    $migration = annotation_events_sequence_migration();
    $migration->down();
    $node = Node::query()->create(['name' => 'revisions', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.61', 'wireguard_ip' => '10.44.0.61']);
    $project = Project::query()->create(['name' => 'Revisions', 'slug' => 'revisions', 'repository_url' => 'https://example.test/revisions.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'dev', 'environment' => 'development', 'source_layout' => 'worktree', 'checkout_path' => '/revisions', 'status' => 'active']);
    $screenshot = json_encode(['screenshot' => 'data:image/png;base64,'.str_repeat('A', 200_000)], JSON_THROW_ON_ERROR);
    foreach ([$instance->id, null, $instance->id, $instance->id] as $owner) {
        DB::table('annotation_events')->insert(['instance_id' => $owner, 'payload' => $screenshot, 'created_at' => now(), 'updated_at' => now()]);
    }
    $ids = DB::table('annotation_events')->orderBy('id')->pluck('id')->all();
    // A deleted top row leaves the counter above the highest id; a rebuild alone would hand that revision out again.
    DB::table('annotation_events')->where('id', max($ids))->delete();
    $kept = array_slice($ids, 0, -1);
    $counter = annotation_events_counter();

    $migration->up();

    expect(Schema::getColumnListing('annotation_events'))->toBe(['id', 'created_at', 'updated_at'])
        ->and(annotation_events_index_exists())->toBeFalse()
        ->and(DB::table('annotation_events')->orderBy('id')->pluck('id')->all())->toBe($kept)
        ->and(annotation_events_counter())->toBe($counter)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    $migration->down();

    expect(Schema::hasColumns('annotation_events', ['instance_id', 'payload']))->toBeTrue()
        ->and(annotation_events_index_exists())->toBeTrue()
        ->and(DB::table('annotation_events')->orderBy('id')->pluck('id')->all())->toBe($kept)
        ->and(DB::table('annotation_events')->whereNotNull('instance_id')->count())->toBe(0)
        ->and(DB::table('annotation_events')->distinct()->pluck('payload')->all())->toBe(['{}'])
        ->and(annotation_events_counter())->toBe($counter);

    $migration->up();

    expect(DB::table('annotation_events')->insertGetId(['created_at' => now(), 'updated_at' => now()]))->toBe($counter + 1);
});

it('refuses to rebuild the table while a trigger reads it', function (): void {
    $migration = annotation_events_sequence_migration();
    $migration->down();
    DB::statement('CREATE TRIGGER annotation_events_audit AFTER INSERT ON annotation_events BEGIN SELECT 1; END');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'annotation_events_audit');
    expect(Schema::hasColumns('annotation_events', ['instance_id', 'payload']))->toBeTrue()
        ->and(annotation_events_index_exists())->toBeTrue();

    DB::statement('DROP TRIGGER annotation_events_audit');
    $migration->up();
});
