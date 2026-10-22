<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Larastan\Larastan\Properties\MigrationHelper;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use PHPStan\DependencyInjection\ContainerFactory;

function schema_baseline_larastan(): MigrationHelper
{
    static $scanner = null;

    // PHPStan's CLI normally sets this after booting the application; Pest already booted it.
    if (! defined('LARAVEL_VERSION')) {
        define('LARAVEL_VERSION', app()->version());
    }

    return $scanner ??= (new ContainerFactory(base_path()))->create(
        sys_get_temp_dir().'/schema-baseline-phpstan',
        [base_path('phpstan.neon')],
        [],
    )->getByType(MigrationHelper::class);
}

it('includes the baseline in the Larastan migration cache inputs before scanning tables', function (): void {
    $files = array_map(static fn (SplFileInfo $file): string => $file->getRealPath(), schema_baseline_larastan()->getMigrationFiles());

    expect($files)->toContain(realpath(database_path('migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php')));
});

it('exposes every baseline table and column to Larastan with its SQLite name and nullability', function (): void {
    $tables = schema_baseline_larastan()->initializeTables();
    // The migrations table belongs to Laravel's repository, not an application migration.
    $names = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')
        ->where('name', '!=', 'migrations')->orderBy('name')->pluck('name')->all();

    expect(array_keys($tables))->toEqualCanonicalizing($names);

    foreach ($names as $name) {
        $columns = Schema::getColumns($name);
        expect(array_keys($tables[$name]->columns))->toEqualCanonicalizing(array_column($columns, 'name'));

        foreach ($columns as $column) {
            expect($tables[$name]->columns[$column['name']]->nullable)->toBe($column['nullable'], $name.'.'.$column['name']);
        }
    }

    $repository = Schema::getColumns('migrations');
    expect(array_column($repository, 'name'))->toBe(['id', 'migration', 'batch']);
    foreach ($repository as $column) {
        expect($column['nullable'])->toBeFalse();
    }
});

it('restores the reference rows omitted by the baseline on a fresh install', function (): void {
    expect((array) DB::table('project_document_storages')->sole())->toBe([
        'id' => 1,
        'endpoint' => null,
        'region' => null,
        'bucket' => null,
        'access_key_id' => null,
        'secret_access_key' => null,
        'created_at' => null,
        'updated_at' => null,
    ])->and((array) DB::table('task_vm_image_recipe')->sole())->toBe([
        'id' => 1,
        'identity' => null,
        'recipe_json' => null,
        'generation' => 0,
        'upcloud_rebuild_after_attempt' => null,
    ]);
});

it('preserves configured singleton rows when deploying over the applied migration history', function (): void {
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'https://storage.example.test',
        'bucket' => 'documents',
        'secret_access_key' => 'existing-secret',
    ]);
    DB::table('task_vm_image_recipe')->where('id', 1)->update([
        'identity' => str_repeat('a', 64),
        'recipe_json' => '{"existing":"recipe"}',
        'generation' => 7,
        'upcloud_rebuild_after_attempt' => 4,
    ]);
    $storage = DB::table('project_document_storages')->get()->all();
    $recipe = DB::table('task_vm_image_recipe')->get()->all();
    DB::table('migrations')->where('migration', '2026_12_04_000000_seed_schema_baseline_reference_rows')->delete();

    // The baseline must be skipped, not attempt to recreate these populated tables.
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $migration = require database_path('migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php');
    $baseline = require database_path('migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php');

    $migration->down();

    expect(DB::table('project_document_storages')->get()->all())->toEqual($storage)
        ->and(DB::table('task_vm_image_recipe')->get()->all())->toEqual($recipe)
        ->and($storage[0]->secret_access_key)->not->toBe('existing-secret')
        ->and(ProjectDocumentStorage::query()->findOrFail(1)->secret_access_key)->toBe('existing-secret')
        ->and(fn () => $baseline->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
});

it('allows earlier vendor migrations to occupy history IDs before the baseline runs', function (): void {
    $history = DB::table('migrations')->pluck('migration')->all();
    $vendorMigration = '2026_07_27_000000_create_vendor_fixture_table';
    config()->set('database.connections.baseline_vendor', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]);
    $connection = DB::connection('baseline_vendor');

    try {
        Schema::connection('baseline_vendor')->create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $connection->table('migrations')->insert([
            ['id' => 1, 'migration' => '2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table', 'batch' => 1],
            ['id' => 2, 'migration' => $vendorMigration, 'batch' => 1],
        ]);

        $this->artisan('migrate', ['--database' => 'baseline_vendor', '--force' => true])->assertExitCode(0);

        expect($connection->table('migrations')->pluck('migration')->all())->toEqualCanonicalizing([...$history, $vendorMigration])
            ->and((array) $connection->table('migrations')->where('id', 2)->sole())->toBe([
                'id' => 2,
                'migration' => $vendorMigration,
                'batch' => 1,
            ]);
    } finally {
        DB::purge('baseline_vendor');
    }
});

it('records the complete squashed history exactly once and leaves no pending migrations', function (): void {
    $history = DB::table('migrations')->orderBy('id')->pluck('migration')->all();

    $migrations = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(database_path('migrations/*.php')),
    );
    // The baseline restores 263 earlier records; Laravel then records it and each later migration.
    $expectedCount = 263 + count($migrations);

    expect($history)->toHaveCount($expectedCount)
        ->and(array_unique($history))->toHaveCount($expectedCount)
        ->and($history[0])->toBe('2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table')
        ->and($history)->toContain('2026_10_21_200327_add_private_endpoints_to_database_servers', '2026_10_22_055111_add_locale_to_database_connections_table')
        ->and($history[263])->toBe('2026_12_03_000000_allow_cold_recipe_image_rebuilds')
        ->and(array_slice($history, 263))->toBe($migrations);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($history);
});
