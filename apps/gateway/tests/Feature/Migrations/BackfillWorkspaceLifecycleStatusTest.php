<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('backfills legacy active workspace lifecycle rows to expected without changing pending rows', function (): void {
    $activeWorkspace = Workspace::factory()->create();
    $pendingWorkspace = Workspace::factory()->create();

    DB::table('workspaces')->where('id', $activeWorkspace->id)->update(['lifecycle_status' => 'active']);
    DB::table('workspaces')->where('id', $pendingWorkspace->id)->update(['lifecycle_status' => 'setup-pending']);

    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_08_16_170000_backfill_workspace_lifecycle_status.php',
        );

    expect($migration)->toBeInstanceOf(Migration::class);

    $migration->up();

    expect(DB::table('workspaces')->where('id', $activeWorkspace->id)->value('lifecycle_status'))
        ->toBe('expected')
        ->and(DB::table('workspaces')->where('id', $pendingWorkspace->id)->value('lifecycle_status'))
        ->toBe('setup-pending');
});
