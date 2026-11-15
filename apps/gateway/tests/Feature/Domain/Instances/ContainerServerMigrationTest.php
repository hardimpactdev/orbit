<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

/** The container server's triggers on `instance_runtimes`, by name. */
function containerServerTriggers(): array
{
    return DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'instance_runtimes_container_server_%')->orderBy('name')->pluck('name')->all();
}

/*
 * The live Gateway's Projects and runtimes exist before the migration (docs/reference/development-containers.md#container-server). Every
 * Project then runs T3, every runtime with a T3 port runs T3, and a runtime without one runs no server. A runtime left without its server
 * could not be fixed afterwards: the value never changes once it is recorded.
 */
it('records t3 for every Project and every runtime with a T3 port that exist before it, and none for a runtime without one', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $migration = require database_path('migrations/2027_02_22_000000_add_container_server.php');
    $migration->down();
    expect(Schema::hasColumn('projects', 'container_server'))->toBeFalse()
        ->and(Schema::hasColumn('instance_runtimes', 'container_server'))->toBeFalse();

    // The rows as the release before the migration wrote them, without the column.
    $runtime = static function (string $name, string $address, ?int $port) use ($node, $project): int {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/test/'.$name,
            'status' => 'active', 'first_setup_pending' => false]);
        DB::table('instance_runtimes')->insert(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => $address, 't3_port' => $port, 'runtime_version' => 'r1', 'agent_version' => 'a1',
            'power_state' => 'running', 'created_at' => now(), 'updated_at' => now()]);

        return $instance->id;
    };
    $default = $runtime('default', '10.253.0.2', null);
    $clone = $runtime('feature', '10.253.0.3', 3800);

    $migration->up();

    expect(DB::table('projects')->where('id', $project->id)->value('container_server'))->toBe('t3')
        ->and(Project::query()->findOrFail($project->id)->container_server)->toBe(ContainerServer::T3)
        ->and(DB::table('instance_runtimes')->where('instance_id', $default)->value('container_server'))->toBeNull()
        ->and(DB::table('instance_runtimes')->where('instance_id', $clone)->value('container_server'))->toBe('t3')
        ->and(InstanceRuntime::query()->where('instance_id', $clone)->sole()->server())->toBe(ContainerServer::T3)
        ->and(containerServerTriggers())->toBe(['instance_runtimes_container_server_insert', 'instance_runtimes_container_server_update']);
});

it('removes both columns and both triggers on the way down', function (): void {
    $migration = require database_path('migrations/2027_02_22_000000_add_container_server.php');

    $migration->down();

    expect(Schema::hasColumn('projects', 'container_server'))->toBeFalse()
        ->and(Schema::hasColumn('instance_runtimes', 'container_server'))->toBeFalse()
        ->and(containerServerTriggers())->toBe([]);
    $migration->up();
});
