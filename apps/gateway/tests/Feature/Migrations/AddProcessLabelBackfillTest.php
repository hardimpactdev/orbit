<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Restore a pre-label processes schema without using a migration down().
 * RefreshDatabase has already applied the label migration; the test drops the
 * column so the real up() path can be exercised against existing name rows.
 */
function restorePreProcessLabelSchema(): void
{
    if (! Schema::hasColumn('processes', 'label')) {
        return;
    }

    Schema::table('processes', static function (Blueprint $table): void {
        $table->dropColumn('label');
    });
}

function processLabelMigration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_08_04_220000_add_label_to_processes_table.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Process label migration must expose up().');
    }

    return $migration;
}

/**
 * @return array<string, mixed>|null
 */
function processLabelColumn(): ?array
{
    foreach (Schema::getColumns('processes') as $column) {
        if (($column['name'] ?? null) === 'label') {
            return $column;
        }
    }

    return null;
}

it('backfills existing process rows label=name and enforces a non-null label column', function (): void {
    $node = Node::factory()->create(['name' => 'app-label-backfill']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'vite',
            'label' => 'Custom Pre-Migration Label',
        ]);

    restorePreProcessLabelSchema();

    expect(Schema::hasColumn('processes', 'label'))->toBeFalse();

    $preMigration = DB::table('processes')->where('id', $process->id)->first();

    expect($preMigration)
        ->not
        ->toBeNull()
        ->and($preMigration->name)
        ->toBe('vite')
        ->and(property_exists($preMigration, 'label') || isset($preMigration->label))
        ->toBeFalse();

    processLabelMigration()->up();

    expect(Schema::hasColumn('processes', 'label'))->toBeTrue();

    $postMigration = DB::table('processes')->where('id', $process->id)->first();
    $labelColumn = processLabelColumn();

    expect($postMigration)
        ->not
        ->toBeNull()
        ->and($postMigration->name)
        ->toBe('vite')
        ->and($postMigration->label)
        ->toBe('vite')
        ->and($labelColumn)
        ->toBeArray()
        ->and($labelColumn['nullable'] ?? true)
        ->toBeFalse();
});

it('defaults new process labels to the identity name when omitted after migration', function (): void {
    $node = Node::factory()->create(['name' => 'app-label-default']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'queue',
        ]);

    expect($process->fresh()->label)->toBe('queue');
});
