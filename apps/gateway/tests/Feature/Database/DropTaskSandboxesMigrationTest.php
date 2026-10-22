<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('leaves no old task sandbox schema after migration', function (): void {
    $index = collect(DB::select("PRAGMA index_list('instances')"))->firstWhere('name', 'instances_node_id_checkout_path_unique');

    expect(Schema::hasTable('task_sandboxes'))->toBeFalse()
        ->and(Schema::hasColumn('instances', 'task_sandbox_id'))->toBeFalse()
        ->and(Schema::hasColumn('nodes', 'compute_sandbox_id'))->toBeFalse()
        ->and(Schema::hasColumn('task_checks', 'task_sandbox_id'))->toBeFalse()
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->where('sql', 'like', '%sandbox%')->count())->toBe(0)
        ->and($index)->not->toBeNull()
        ->and($index->unique)->toBe(1)
        ->and($index->partial)->toBe(0)
        ->and(array_column(DB::select("PRAGMA index_info('instances_node_id_checkout_path_unique')"), 'name'))
        ->toBe(['node_id', 'checkout_path']);
});
