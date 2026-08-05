<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses App as the logical model while retaining apps table storage', function (): void {
    expect(new App()->getTable())
        ->toBe('apps')
        ->and(Schema::hasTable('apps'))
        ->toBeTrue()
        ->and(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instances'))
        ->toBeFalse();
});

it('keeps concrete runtime and workspace ownership keys unchanged', function (): void {
    expect(Schema::hasColumn('workspaces', 'instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('workspace_steps', 'instance_id'))
        ->toBeTrue();
});

it('hydrates process morph types with App owner class', function (): void {
    $app = App::factory()->create(['name' => 'docs']);
    $process = Process::factory()->forOwner($app)->create();

    expect($process->owner_type)->toBe('App\\Models\\App');

    DB::table('processes')
        ->where('id', $process->id)
        ->update([
            'owner_type' => 'App\\Models\\App',
        ]);

    expect($process->fresh()?->owner)->toBeInstanceOf(App::class);
});
