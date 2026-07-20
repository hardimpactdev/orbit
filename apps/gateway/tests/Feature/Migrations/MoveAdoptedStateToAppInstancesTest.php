<?php

declare(strict_types=1);

use App\Models\AppInstance;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('backfills project adoption state onto every existing concrete instance', function (): void {
    $project = Project::factory()->create(['adopted' => true]);
    $instances = AppInstance::factory()
        ->count(2)
        ->sequence(
            ['name' => 'development'],
            ['name' => 'production'],
        )
        ->for($project)
        ->create(['adopted' => false]);

    $migration = require
        database_path(
            'migrations/2026_07_20_100000_add_adopted_to_app_instances_table.php',
        );

    expect($migration)->toBeInstanceOf(Migration::class);

    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('app_instances', 'adopted'))
        ->toBeTrue()
        ->and(
            DB::table('app_instances')
                ->whereIn('id', $instances->pluck('id'))
                ->pluck('adopted')
                ->all(),
        )
        ->toBe([1, 1]);
});
