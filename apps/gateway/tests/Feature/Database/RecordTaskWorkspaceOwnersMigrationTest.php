<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.task_workspace_owners' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('task_workspace_owners');
    $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => basename($path) < '2026_11_15_000000_record_task_workspace_owners.php'));
    Artisan::call('migrate', ['--database' => 'task_workspace_owners', '--path' => $paths, '--realpath' => true, '--force' => true]);
});

afterEach(function (): void {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('task_workspace_owners');
});

function owners_migration(): Migration
{
    return require database_path('migrations/2026_11_15_000000_record_task_workspace_owners.php');
}

function owners_group(int $id, ?int $instance = null, ?int $parent = null, string $status = 'completed'): void
{
    DB::table('tasks')->insert([
        'id' => $id, 'parent_id' => $parent, 'position' => $parent === null ? null : 1, 'project_id' => $parent === null ? 1 : null,
        'title' => 'Group', 'brief' => 'Brief', 'status' => $status,
        'taskable_type' => $instance === null ? null : 'instance', 'taskable_id' => $instance,
    ]);
}

function owners_instance(int $id, int $node, string $name, ?string $branch = null): void
{
    DB::table('instances')->insert(['id' => $id, 'project_id' => 1, 'node_id' => $node, 'name' => $name, 'branch_override' => $branch, 'checkout_path' => '/srv/'.$name, 'status' => 'active']);
}

function owners_vm(int $group, int $node, string $state = 'ready'): void
{
    DB::table('task_vms')->insert([
        'group_id' => $group, 'host_node_id' => 1, 'node_id' => $node, 'provider' => 'incus', 'name' => 'tvm-'.$group,
        'state' => $state, 'wireguard_ip' => '10.44.64.'.$group, 'pi_token' => 'pi-token',
    ]);
}

it('records owners from explicit records only, never from names', function (): void {
    DB::table('projects')->insert(['id' => 1, 'name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB', 'repository_url' => 'git@example.test:orbit.git', 'repository_identity' => 'example.test/orbit']);
    foreach (range(1, 6) as $node) {
        DB::table('nodes')->insert(['id' => $node, 'name' => 'node-'.$node, 'public_ssh_host' => '10.44.0.'.$node, 'status' => 'active', 'platform' => 'linux']);
    }
    // An attached workspace on an ordinary Node.
    owners_instance(1, 1, 'task-10', 'task-10');
    owners_group(10, 1);
    // A workspace a claim provisioned on its group's VM but never attached.
    owners_instance(2, 2, 'task-11', 'task-11');
    owners_group(11);
    owners_vm(11, 2);
    // The VM's group owns its Node's Instance, even when another group holds it.
    owners_instance(3, 3, 'task-12', 'task-12');
    owners_group(12);
    owners_vm(12, 3);
    owners_group(13, 3);
    // Two groups hold one Instance.
    owners_instance(4, 1, 'shared');
    owners_group(14, 4);
    owners_group(15, 4, status: 'running');
    // A destroyed VM is no record.
    owners_instance(5, 4, 'task-16', 'task-16');
    owners_group(16);
    owners_vm(16, 4, 'destroyed');
    // A user Instance named and branched like the workspace of an existing group.
    owners_instance(6, 1, 'task-17', 'task-17');
    owners_group(17);
    // A group that two records name for two Instances owns neither.
    owners_instance(7, 1, 'attached-18');
    owners_instance(8, 5, 'task-18', 'task-18');
    owners_group(18, 7, status: 'running');
    DB::table('tasks')->where('id', 18)->update(['assistance_requested' => true, 'assistance_kind' => 'direction', 'assistance_question' => 'Which branch?', 'assistance_reason' => 'Asked.']);
    owners_vm(18, 5);
    // A subtask's Instance is no record.
    owners_instance(9, 1, 'subtask');
    owners_group(19, null, 17);
    DB::table('tasks')->where('id', 19)->update(['taskable_type' => 'instance', 'taskable_id' => 9]);

    owners_migration()->up();

    expect(DB::table('instances')->orderBy('id')->pluck('task_workspace_owner_id', 'id')->all())->toBe([
        1 => 10, 2 => 11, 3 => 12, 4 => null, 5 => null, 6 => null, 7 => null, 8 => null, 9 => null,
    ]);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    // A group holds only the workspace it owns. The others lose their attachment; an open group asks for assistance.
    expect(DB::table('tasks')->whereNull('parent_id')->whereNotNull('taskable_id')->orderBy('id')->pluck('taskable_id', 'id')->all())->toBe([10 => 1]);
    $reason = 'Orbit detached the Instance of this task because the task does not own it. Cancel the task and start a new one.';
    expect((array) DB::table('tasks')->where('id', 15)->first(['assistance_requested', 'assistance_kind', 'assistance_reason']))
        ->toBe(['assistance_requested' => 1, 'assistance_kind' => 'failure', 'assistance_reason' => $reason]);
    expect(DB::table('tasks')->where('id', 14)->value('assistance_requested'))->toBe(0);
    expect(DB::table('tasks')->where('id', 18)->value('assistance_kind'))->toBe('direction');
    expect(DB::table('tasks')->where('id', 19)->value('taskable_id'))->toBe(9);

    // The owner never changes once set, and an Instance without one never gains one.
    expect(fn () => DB::table('instances')->where('id', 2)->update(['task_workspace_owner_id' => 12]))->toThrow(QueryException::class, 'A task workspace owner never changes.');
    expect(fn () => DB::table('instances')->where('id', 6)->update(['task_workspace_owner_id' => 17]))->toThrow(QueryException::class, 'A task workspace owner never changes.');
    expect(DB::table('instances')->where('id', 2)->value('task_workspace_owner_id'))->toBe(11);

    DB::table('tasks')->where('id', 10)->delete();
    expect(DB::table('instances')->where('id', 1)->value('task_workspace_owner_id'))->toBeNull();

    owners_migration()->down();
    expect(Schema::hasColumn('instances', 'task_workspace_owner_id'))->toBeFalse();
});
