<?php

declare(strict_types=1);

use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

/*
 * The live defaults, Instance 2454 and every fixture default, have runtime rows from before the inner volume. After the migration they
 * must read no inner volume, or every clone and task copy of them would try to copy a volume and a volume seed that do not exist.
 */
it('gives a runtime row made before the migration no inner volume, so its clone copies no volume', function (): void {
    $migration = require database_path('migrations/2027_02_14_000000_add_inner_volume_to_instance_runtimes.php');
    $migration->down();
    expect(Schema::hasColumn('instance_runtimes', 'inner_volume'))->toBeFalse();

    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default',
        'status' => 'active', 'first_setup_pending' => false]);
    // The row as the release before the migration wrote it, without the column.
    DB::table('instance_runtimes')->insert(['instance_id' => $default->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances-nested',
        'container_name' => 'instance-'.$default->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r1', 'agent_version' => 'a1',
        'power_state' => 'running', 'seed_commit' => str_repeat('c', 40), 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    $runtime = InstanceRuntime::query()->where('instance_id', $default->id)->sole();
    expect(DB::table('instance_runtimes')->where('instance_id', $default->id)->value('inner_volume'))->toBeNull()
        ->and($runtime->inner_volume)->toBeNull()
        ->and(app(DevelopmentInstanceCreateAdmission::class)->readyDefault($project, $default->refresh()))->toBeInstanceOf(Instance::class);
});
