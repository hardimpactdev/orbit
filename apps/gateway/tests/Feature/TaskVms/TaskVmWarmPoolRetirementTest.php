<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

it('retires every unassigned VM without deleting rows or changing other Node settings', function (): void {
    $host = Node::query()->create(['name' => 'retired-pool', 'status' => 'active', 'public_ssh_host' => '192.0.2.7',
        'settings' => ['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'warm_vms' => 0], 't3' => ['id' => 'keep']]]);
    foreach (['warm', 'provisioning', 'failed', 'destroyed'] as $index => $state) {
        $vm = TaskVm::query()->create(['provider' => 'incus', 'host_node_id' => $host->id, 'name' => 'tvm-retired-'.$index,
            'wireguard_ip' => '10.44.0.'.(130 + $index), 'state' => $state === 'warm' ? 'provisioning' : $state]);
        if ($state === 'warm') {
            DB::table('task_vms')->where('id', $vm->id)->update(['state' => 'warm']);
        }
    }
    $migration = require database_path('migrations/2026_11_08_000000_retire_task_vm_warm_pool.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect(TaskVm::query()->orderBy('id')->get()->pluck('state')->all())->toBe([
        TaskVmState::Destroying, TaskVmState::Destroying, TaskVmState::Destroying, TaskVmState::Destroyed,
    ])->and($host->refresh()->settings)->toBe([
        'task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4], 't3' => ['id' => 'keep'],
    ]);
    Queue::fake();
    TaskVmSettingsBuilder::bind(['enabled' => false]);
    app()->call([new SweepTaskVmHosts, 'handle']);
    Queue::assertPushed(DestroyTaskVm::class, 3);
});

it('keeps assigned VMs and safely fails an invalid assigned warm row', function (): void {
    $project = Project::query()->create(['name' => 'Retirement', 'slug' => 'retirement', 'repository_url' => 'https://example.test/repo.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);
    $host = Node::query()->create(['name' => 'retired-pool', 'status' => 'active', 'public_ssh_host' => '192.0.2.7']);
    $vm = TaskVm::query()->create(['provider' => 'incus', 'group_id' => $group->id, 'host_node_id' => $host->id,
        'name' => 'tvm-assigned', 'wireguard_ip' => '10.44.0.130', 'state' => TaskVmState::Ready]);
    $migration = require database_path('migrations/2026_11_08_000000_retire_task_vm_warm_pool.php');

    $migration->up();
    expect($vm->refresh()->state)->toBe(TaskVmState::Ready);
    DB::table('task_vms')->where('id', $vm->id)->update(['state' => 'warm']);
    $migration->up();

    expect($vm->refresh()->state)->toBe(TaskVmState::Failed)
        ->and($vm->error_code)->toBe('task_vm.warm_pool_removed')->and($vm->group_id)->toBe($group->id);
});
