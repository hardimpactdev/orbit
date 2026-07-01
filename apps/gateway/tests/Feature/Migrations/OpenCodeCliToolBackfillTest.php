<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runOpenCodeCliToolBackfillMigration(): void
{
    $migration = require
        base_path('database/migrations/2026_07_01_000001_rename_opencode_tool_slug_to_opencode_cli.php');

    $migration->up();
}

it('backfills legacy opencode-server node tool rows to opencode-cli', function (): void {
    $node = Node::factory()->create(['name' => 'app-oc-backfill-1']);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
    ]);

    runOpenCodeCliToolBackfillMigration();

    expect(
        DB::table('node_tools')
            ->where('node_id', $node->id)
            ->where('name', 'opencode-cli')
            ->exists(),
    )
        ->toBeTrue()
        ->and(
            DB::table('node_tools')
                ->where('node_id', $node->id)
                ->where('name', 'opencode-server')
                ->exists(),
        )
        ->toBeFalse();
});

it('removes duplicate legacy opencode-server tool rows when canonical rows already exist', function (): void {
    $node = Node::factory()->create(['name' => 'app-oc-backfill-duplicate']);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-cli',
        'expected_state' => 'installed',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'absent',
    ]);

    runOpenCodeCliToolBackfillMigration();

    expect(
        DB::table('node_tools')
            ->where('node_id', $node->id)
            ->where('name', 'opencode-cli')
            ->count(),
    )
        ->toBe(1)
        ->and(
            DB::table('node_tools')
                ->where('node_id', $node->id)
                ->where('name', 'opencode-server')
                ->exists(),
        )
        ->toBeFalse();
});

it('backfills legacy opencode process tool dependencies to opencode-cli', function (): void {
    $node = Node::factory()->create(['name' => 'app-oc-backfill-2']);

    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'runtime' => 'systemd',
            'tool' => 'opencode',
        ]);

    runOpenCodeCliToolBackfillMigration();

    $process = DB::table('processes')
        ->where('node_id', $node->id)
        ->where('name', 'opencode-server')
        ->first();

    expect($process)
        ->not
        ->toBeNull()
        ->and($process->tool)
        ->toBe('opencode-cli')
        ->and($process->command)
        ->toBe('opencode serve -a');
});

it('backfills process dependencies that used the legacy opencode-server tool slug', function (): void {
    $node = Node::factory()->create(['name' => 'app-oc-backfill-3']);

    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'runtime' => 'systemd',
            'tool' => 'opencode-server',
        ]);

    runOpenCodeCliToolBackfillMigration();

    expect(
        DB::table('processes')
            ->where('node_id', $node->id)
            ->where('name', 'opencode-server')
            ->value('tool'),
    )
        ->toBe('opencode-cli');
});
