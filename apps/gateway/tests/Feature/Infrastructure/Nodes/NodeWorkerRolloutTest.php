<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\Fleet\FleetFixtures;

it('permits a selected task VM host but excludes the task VM Node', function (): void {
    $node = FleetFixtures::node('worker-host');
    config(['swamp_workers.nodes' => [$node->id]]);
    $node->update(['user' => 'nckrtl', 'settings' => ['task_vms' => ['max_vms' => 6]]]);
    $rollout = app(NodeWorkerRollout::class);
    expect($rollout->allows($node))->toBeTrue();
    $project = Project::query()->create(['name' => 'Worker task', 'slug' => 'worker-task', 'repository_url' => 'git@github.com:acme/worker-task.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Task worker', 'brief' => 'Task worker', 'status' => 'backlog']);
    $guest = FleetFixtures::node('worker-guest');
    TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $guest->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-worker', 'wireguard_ip' => $guest->wireguard_ip, 'state' => 'ready']);
    config(['swamp_workers.nodes' => [$node->id, $guest->id]]);
    expect($rollout->allows($node))->toBeTrue()->and($rollout->allows($guest))->toBeFalse();
});
