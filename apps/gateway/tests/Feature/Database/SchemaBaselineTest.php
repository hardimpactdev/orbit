<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Larastan\Larastan\Properties\Schema\MySqlDataTypeToPhpTypeConverter;
use Larastan\Larastan\Properties\SquashedMigrationHelper;
use Larastan\Larastan\SQL\IamcalSqlParser;
use Larastan\Larastan\SQL\SqlParserFactory;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use PHPStan\DependencyInjection\NeonAdapter;
use PHPStan\File\FileHelper;

/** @param list<string> $paths */
function schema_baseline_larastan(array $paths): SquashedMigrationHelper
{
    return new SquashedMigrationHelper(
        $paths,
        new FileHelper(base_path()),
        new MySqlDataTypeToPhpTypeConverter,
        new SqlParserFactory(new IamcalSqlParser)->create(),
        false,
    );
}

it('includes the schema dump in the Larastan migration cache inputs before scanning tables', function (): void {
    $configuration = new NeonAdapter([])->load(base_path('phpstan.neon'));
    $scanner = schema_baseline_larastan($configuration['parameters']['squashedMigrationsPath'] ?? []);
    $files = array_map(static fn (SplFileInfo $file): string => $file->getRealPath(), $scanner->getSchemaFiles());

    expect($files)->toContain(realpath(database_path('schema/sqlite-schema.sql')));
});

it('exposes every baseline table and column to Larastan with its SQLite name and nullability', function (): void {
    $tables = schema_baseline_larastan([database_path('schema')])->initializeTables();
    $names = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')
        ->orderBy('name')->pluck('name')->all();

    expect(array_keys($tables))->toEqualCanonicalizing($names);

    foreach ($names as $name) {
        $columns = Schema::getColumns($name);
        expect(array_keys($tables[$name]->columns))->toEqualCanonicalizing(array_column($columns, 'name'));

        foreach ($columns as $column) {
            expect($tables[$name]->columns[$column['name']]->nullable)->toBe($column['nullable'], $name.'.'.$column['name']);
        }
    }
});

it('restores the reference rows omitted by the schema dump on a fresh install', function (): void {
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
    $migration = require database_path('migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php');

    $migration->up();

    expect(DB::table('project_document_storages')->get()->all())->toEqual($storage)
        ->and(DB::table('task_vm_image_recipe')->get()->all())->toEqual($recipe)
        ->and($storage[0]->secret_access_key)->not->toBe('existing-secret')
        ->and(ProjectDocumentStorage::query()->findOrFail(1)->secret_access_key)->toBe('existing-secret')
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
});
