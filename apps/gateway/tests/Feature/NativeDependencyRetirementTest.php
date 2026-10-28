<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('drops native inventory while preserving Instances and other control-plane state across upgrade and empty rollback', function (): void {
    $migration = require database_path('migrations/2027_01_05_000000_retire_native_instance_dependency_inventory.php');
    $node = Node::query()->create(['name' => 'retirement-owner', 'public_ssh_host' => '192.0.2.80', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'retirement', 'slug' => 'retirement', 'repository_url' => 'https://github.com/fixture/retirement']);
    $instance = $project->instances()->create(['node_id' => $node->id, 'name' => 'kept', 'status' => 'active', 'checkout_path' => '/srv/kept']);
    expect(Schema::hasTable('dependency_packages'))->toBeFalse();
    expect(Schema::hasColumn('instances', 'dependency_sub_projects'))->toBeFalse();
    $migration->down();
    DB::table('dependency_packages')->insert(['ecosystem' => 'composer', 'name' => 'fixture/retired']);
    $migration->up();
    foreach (['dependency_packages', 'instance_dependency_observations', 'instance_dependency_scan_attempts', 'instance_dependency_resolutions', 'instance_dependency_edges'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect($node->fresh()?->name)->toBe('retirement-owner');
    expect($instance->fresh()?->checkout_path)->toBe('/srv/kept');
    $migration->down();
    expect(DB::table('dependency_packages')->count())->toBe(0);
    $migration->up();
});
