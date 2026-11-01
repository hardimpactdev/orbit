<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle',
        'repository_url' => 'https://github.com/example/lifecycle.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'feature', 'checkout_path' => '/srv/apps/lifecycle/feature', 'source_layout' => 'checkout',
        'status' => InstanceState::CheckoutPrepared, 'first_setup_pending' => true]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'domain' => 'feature.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $node->id, 'input' => ['instance_id' => $this->instance->id, 'project_id' => $project->id,
            'name' => 'feature', 'default_branch' => 'main', 'branch_override' => null,
            'repository_identity' => $project->repository_identity, 'checkout' => $this->instance->checkout_path]]);
    $this->state = app(InstanceLifecycleState::class);
});

it('refuses an Operation for another Node before state projection', function (): void {
    $this->operation->node_id++;
    expect(fn () => $this->state->instance($this->operation))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status)->toBe(InstanceState::CheckoutPrepared);
});
