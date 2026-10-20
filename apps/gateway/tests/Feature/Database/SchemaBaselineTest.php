<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;

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
