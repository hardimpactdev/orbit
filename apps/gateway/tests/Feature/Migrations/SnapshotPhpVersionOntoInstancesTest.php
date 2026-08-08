<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Instance;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * RefreshDatabase has already applied the snapshot migration; drop the column
 * so the real up() path runs against pre-snapshot rows.
 */
function restorePreSnapshotPhpSchema(): void
{
    if (Schema::hasColumn('instances', 'php_version')) {
        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropColumn('php_version');
        });
    }
}

function runSnapshotPhpVersionMigration(): void
{
    /** @var Migration $migration */
    $migration = require
        dirname(__DIR__, 3).'/database/migrations/2026_08_07_180000_snapshot_php_version_onto_instances.php';

    $migration->up();
}

it('gives every existing instance the version it resolves to today', function (): void {
    restorePreSnapshotPhpSchema();

    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    $other = App::factory()->create(['name' => 'shop', 'php_version' => '8.5']);
    $docsDev = Instance::factory()->for($app)->create(['name' => 'development']);
    $docsProd = Instance::factory()->for($app)->create(['name' => 'production']);
    $shopDev = Instance::factory()->for($other)->create(['name' => 'development']);

    runSnapshotPhpVersionMigration();

    expect(DB::table('instances')->where('id', $docsDev->id)->value('php_version'))
        ->toBe('8.4')
        ->and(DB::table('instances')->where('id', $docsProd->id)->value('php_version'))
        ->toBe('8.4')
        ->and(DB::table('instances')->where('id', $shopDev->id)->value('php_version'))
        ->toBe('8.5');
});

it('materializes workspaces that were following the app version', function (): void {
    restorePreSnapshotPhpSchema();

    $app = App::factory()->create(['name' => 'docs', 'php_version' => '8.4']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development']);
    $following = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'follows-app',
        'php_version' => null,
    ]);
    $pinned = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'pinned',
        'php_version' => '8.3',
    ]);

    runSnapshotPhpVersionMigration();

    expect(DB::table('workspaces')->where('id', $following->id)->value('php_version'))
        ->toBe('8.4')
        ->and(DB::table('workspaces')->where('id', $pinned->id)->value('php_version'))
        ->toBe('8.3');
});
