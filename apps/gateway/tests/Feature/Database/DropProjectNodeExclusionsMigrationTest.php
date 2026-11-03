<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops the development Node exclusion table and restores it on rollback', function (): void {
    $drop = require database_path('migrations/2027_01_14_120000_drop_project_node_exclusions_table.php');

    expect(Schema::hasTable('project_node_exclusions'))->toBeFalse();

    $drop->down();

    expect(Schema::getColumnListing('project_node_exclusions'))->toBe(['id', 'project_id', 'node_id', 'created_at', 'updated_at'])
        ->and(collect(Schema::getIndexes('project_node_exclusions'))->where('unique', true)->pluck('columns')->all())
        ->toContain(['project_id', 'node_id']);

    $drop->up();

    expect(Schema::hasTable('project_node_exclusions'))->toBeFalse()
        ->and(Schema::hasTable('projects'))->toBeTrue()
        ->and(Schema::hasTable('nodes'))->toBeTrue();
});
