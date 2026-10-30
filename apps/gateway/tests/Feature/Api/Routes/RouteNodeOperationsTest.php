<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->claimOperation = function (string $kind, string $targetType, int $targetId, array $input): void {
        $this->operation = Operation::query()->create(['kind' => $kind, 'target_type' => $targetType, 'target_id' => $targetId,
            'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'worker' => 'worker', 'sequence' => 1, 'input' => $input]);
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => $kind, 'worker' => 'worker', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data.id', $this->operation->id);
        $this->operation->refresh();
        $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    };
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('withdraws a shared PHP pool even when no Route exists', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'unrouted', 'checkout_path' => '/srv/proof/unrouted', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
    $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name, 'force' => false, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $removal->members()->create(['position' => 0, 'instance_id' => $instance->id, 'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => $instance->name, 'environment' => 'development', 'runtime_published' => false, 'root' => 'public', 'branch' => 'main', 'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit, 'repository_identity' => $project->repository_identity, 'common_repository_path' => '/srv/proof/unrouted/.git', 'source_identity' => 'proof-source', 'source_layout' => $instance->source_layout, 'checkout_path' => $instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('a', 64)]);
    $instance->update(['status' => InstanceState::Removing]);
    ($this->claimOperation)('route.withdraw_instance', 'instance_routes', $instance->id, ['instance_id' => $instance->id]);
    $this->agents->answer('php.converge-shared-pools', ['changed' => true, 'missing_pools' => []]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', 'key' => 'instance-runtime-prepare', 'transport' => 'gateway', 'name' => 'route.instance-runtime', 'input' => ['instance_id' => $instance->id, 'action' => 'prepare']])->assertOk();
    $descriptors = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors');
    expect($descriptors[1]['name'])->toBe('php.converge-shared-pools');
    $run = ['worker' => 'worker', ...array_intersect_key($descriptors[1], array_flip(['key', 'transport', 'name', 'input']))];
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.withdrawn', true);
    expect($this->agents->calls)->toHaveCount(1)->and((array) $this->agents->calls[0]['input']['configurations'])->toBe([]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', ...array_intersect_key($descriptors[2], array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->assertJsonPath('data.ready', true);
});
