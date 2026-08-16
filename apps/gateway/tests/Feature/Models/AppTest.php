<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('App model', function (): void {
    it('persists the canonical logical app registry fields', function (): void {
        $app = App::query()->create([
            'name' => 'docs',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);

        $app->refresh();

        expect($app->name)
            ->toBe('docs')
            ->and($app->repository)
            ->toBe('git@github.com:orbit/docs.git')
            ->and($app->php_version)
            ->toBe('8.5')
            ->and($app->runtime)
            ->toBe(AppRuntimeKind::Php);
    });

    it('defaults optional logical fields for a new app', function (): void {
        $app = App::query()->create([
            'name' => 'api',
        ]);

        $app->refresh();

        expect($app->repository)
            ->toBeNull()
            ->and($app->php_version)
            ->toBe('8.5')
            ->and($app->runtime)
            ->toBe(AppRuntimeKind::Php)
            ->and($app->runtime_config)
            ->toBeNull();
    });

    it('does not carry placement or adoption on the logical apps table', function (): void {
        expect(Schema::hasTable('apps'))
            ->toBeTrue()
            ->and(Schema::hasColumns('apps', [
                'id',
                'name',
                'repository',
                'php_version',
                'runtime',
                'runtime_config',
                'created_at',
                'updated_at',
            ]))
            ->toBeTrue();

        // Placement/adoption is authoritative on concrete Orbit instances; the
        // apps table must not reintroduce the removed shadow columns.
        foreach (['node_id', 'environment', 'domain', 'path', 'document_root', 'adopted'] as $shadowColumn) {
            expect(Schema::hasColumn('apps', $shadowColumn))->toBeFalse();
        }
    });
});
