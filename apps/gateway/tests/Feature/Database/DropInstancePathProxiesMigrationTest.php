<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops the shipped path proxy table and restores its schema on rollback', function (): void {
    $create = require database_path('migrations/2026_12_31_000000_create_instance_path_proxies_table.php');
    $drop = require database_path('migrations/2027_01_01_000000_drop_instance_path_proxies_table.php');
    $create->up();
    $columns = Schema::getColumns('instance_path_proxies');
    $indexes = Schema::getIndexes('instance_path_proxies');
    $foreignKeys = Schema::getForeignKeys('instance_path_proxies');

    $drop->up();

    expect(Schema::hasTable('instance_path_proxies'))->toBeFalse()
        ->and(Schema::hasTable('instances'))->toBeTrue()
        ->and(Schema::hasTable('routes'))->toBeTrue()
        ->and(Schema::hasTable('processes'))->toBeTrue();

    $drop->down();

    expect(Schema::getColumns('instance_path_proxies'))->toBe($columns)
        ->and(Schema::getIndexes('instance_path_proxies'))->toBe($indexes)
        ->and(Schema::getForeignKeys('instance_path_proxies'))->toBe($foreignKeys);
    $drop->up();
});
