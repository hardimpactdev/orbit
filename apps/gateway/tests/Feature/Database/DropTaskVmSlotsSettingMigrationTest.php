<?php

declare(strict_types=1);

use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The task_vms indexes the migration owns, by name. */
function slotMigrationIndexes(): array
{
    return DB::table('sqlite_master')->where('tbl_name', 'task_vms')->where('type', 'index')
        ->whereIn('name', ['task_vms_name_unique', 'task_vms_host_slot_unique'])->pluck('sql', 'name')->all();
}

it('runs again on a Gateway where a run stopped half way and the name index was already gone', function (): void {
    // The live state after release 1770: the settings and the column were done, then DROP INDEX failed on a missing index.
    DB::statement('DROP INDEX IF EXISTS task_vms_name_unique');
    DB::statement('DROP INDEX IF EXISTS task_vms_host_slot_unique');
    $migration = require database_path('migrations/2027_01_25_000000_drop_task_vm_slots_setting.php');

    $migration->up();
    $migration->up();

    $indexes = slotMigrationIndexes();
    expect(Schema::hasColumn('task_vms', 'slot_digest'))->toBeFalse()
        ->and($indexes['task_vms_name_unique'] ?? '')->toContain("WHERE state != 'destroyed'")
        ->and($indexes['task_vms_host_slot_unique'] ?? '')->toContain('slot_number IS NOT NULL');
});

it('hands the ready slots of the old pool to the destroy, and leaves group VMs and slot rows alone', function (): void {
    $host = DB::table('nodes')->insertGetId(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit', 'created_at' => now(), 'updated_at' => now()]);
    $row = static fn (string $name, array $extra): int => DB::table('task_vms')->insertGetId([...['host_node_id' => $host, 'provider' => 'incus', 'size' => 'normal',
        'name' => $name, 'state' => 'parked', 'created_at' => now(), 'updated_at' => now()], ...$extra]);
    $ready = $row('tvm-875', ['slot_project_id' => 66, 'wireguard_ip' => '10.44.0.131']);
    $slot = $row('beast-tvm-1', ['slot_project_id' => 46, 'slot_number' => 1, 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.132']);
    $grouped = $row('tvm-610', ['wireguard_ip' => '10.44.0.133']);

    (require database_path('migrations/2027_01_26_000000_retire_ready_task_vm_slots.php'))->up();

    expect(DB::table('task_vms')->where('id', $ready)->value('state'))->toBe('destroying')
        ->and(DB::table('task_vms')->where('id', $slot)->value('state'))->toBe('provisioning')
        ->and(DB::table('task_vms')->where('id', $grouped)->value('state'))->toBe('parked');
});

it('drops the columns of the deleted provision and destroy paths, and runs again where they are gone', function (): void {
    expect(Schema::hasColumn('task_vms', 'provider_bootstrap'))->toBeFalse()->and(Schema::hasColumn('task_vms', 'worker_stop_required'))->toBeFalse()
        // What park and slot allocation still read stays. The later UpCloud retirement removes provider_id.
        ->and(Schema::hasColumns('task_vms', ['image_fingerprint', 'stop_confirmed_at', 'slot_number', 'wireguard_private_key', 'agent_secret']))->toBeTrue()
        ->and(Schema::hasColumn('task_vms', 'provider_id'))->toBeFalse();

    $migration = require database_path('migrations/2027_01_29_000000_drop_old_task_vm_columns.php');
    $migration->up();
    $migration->up();
});

it('unbinds groups whose workspace Instance a release deleted, and keeps every other binding', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark-tvm-1', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.131', 'wireguard_ip' => '10.44.0.131', 'user' => 'orbit']));
    $workspace = static function (string $name) use ($project, $node): array {
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Failed]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/home/orbit/apps/orbit/'.$name,
            'task_workspace_owner_id' => $group->id]);
        $group->taskable()->associate($instance)->save();

        return [$group->id, $instance->id];
    };
    [$bound, $kept] = $workspace('task-1');
    [$dangling, $deleted] = $workspace('task-2');
    // The release deleted the Instance before it unbound groups.
    DB::table('instances')->where('id', $deleted)->delete();

    (require database_path('migrations/2027_01_27_000000_unbind_groups_from_deleted_workspaces.php'))->up();

    expect(DB::table('tasks')->where('id', $bound)->value('taskable_id'))->toBe($kept)
        ->and(DB::table('tasks')->where('id', $dangling)->value('taskable_id'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $dangling)->value('taskable_type'))->toBeNull();
});

it('migrates existing hosts to readable and updateable separate pool settings without losing other settings', function (): void {
    $ids = [];
    foreach (['Beast', 'shark', 'sabre'] as $index => $name) {
        $ids[$name] = DB::table('nodes')->insertGetId(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => '10.44.0.'.($index + 10), 'wireguard_ip' => '10.44.0.'.($index + 10), 'user' => 'orbit',
            'settings' => json_encode(['task_vms' => ['cidr' => '10.252.7.0/24', 'max_vms' => 6, 'task_max_vms' => 1, 'ci_only' => $name === 'sabre', 'cpus' => 4],
                'apps' => ['path' => '/fast/apps'], 't3' => ['project' => 'orbit']])]);
    }
    $migration = require database_path('migrations/2027_02_01_000001_migrate_task_vm_host_pools.php');
    $migration->up();
    $migration->up();
    $normalizer = new NodeSettingsNormalizer;
    foreach ($ids as $name => $id) {
        $node = Node::query()->findOrFail($id);
        $data = NodeData::fromModel($node);
        expect($data->settings->task_vms->task_places)->toBe($name === 'sabre' ? 0 : 2)
            ->and($data->settings->task_vms->ci_places)->toBe(2);
        $updated = $normalizer->storedOver($node->settings, new NodeSettingsData(
            apps: new NodeStorageAppsData('/new/apps'), task_vms: $data->settings->task_vms));
        $node->update(['settings' => $updated]);
        expect($node->fresh()->settings['task_vms'])->not->toHaveKeys(['max_vms', 'task_max_vms', 'ci_only'])
            ->and($node->settings['task_vms']['cpus'])->toBe(4)
            ->and($node->settings['t3'])->toBe(['project' => 'orbit'])
            ->and($node->settings['apps']['path'])->toBe('/new/apps');
    }
});
