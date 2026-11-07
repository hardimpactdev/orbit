<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('drops both package cache tables, and runs again after a run that stopped half way', function (): void {
    // The state before the release: the snapshot table exists. The entry table is already gone, as after a run that stopped between the two drops.
    Schema::create('package_cache_snapshots', static function (Blueprint $table): void {
        $table->integer('project_id')->primary();
    });
    $migration = require database_path('migrations/2027_01_29_000000_drop_the_package_cache_tables.php');

    $migration->up();
    $migration->up();

    expect(Schema::hasTable('package_cache_snapshots'))->toBeFalse()->and(Schema::hasTable('package_cache_entries'))->toBeFalse();
});
