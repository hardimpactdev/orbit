<?php

declare(strict_types=1);

use App\Models\Process;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses Project as the logical model while retaining recoverable storage identifiers', function (): void {
    expect(new Project()->getTable())
        ->toBe('apps')
        ->and(Schema::hasTable('apps'))
        ->toBeTrue()
        ->and(Schema::hasTable('projects'))
        ->toBeFalse();
});

it('keeps concrete runtime and workspace ownership keys unchanged', function (): void {
    expect(Schema::hasColumn('workspaces', 'app_instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('workspace_steps', 'app_instance_id'))
        ->toBeTrue();
});

it('hydrates legacy process morph types and keeps new rows readable by the previous gateway image', function (): void {
    $project = Project::factory()->create(['name' => 'docs']);
    $process = Process::factory()->forOwner($project)->create();

    expect($process->owner_type)->toBe('App\\Models\\App');

    DB::table('processes')
        ->where('id', $process->id)
        ->update([
            'owner_type' => 'App\\Models\\App',
        ]);

    expect($process->fresh()?->owner)->toBeInstanceOf(Project::class);
});
