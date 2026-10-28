<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_caddy', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'worker' => 'worker', 'sequence' => 1, 'input' => ['reason' => 'dev proof']]);
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.publish', ['result' => 'published']);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->prepare = ['worker' => 'worker', 'key' => 'prepare', 'transport' => 'gateway', 'name' => 'route.caddy-prepare', 'input' => ['reason' => 'dev proof']];
    $this->publish = function (): array {
        $this->postJson($this->path.'/run', $this->prepare)->assertOk()->assertJsonPath('data.prepared', true);
        $descriptor = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors.1');

        return ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))];
    };
});

it('publishes only the stored whole-Node render and reuses its receipt', function (): void {
    $run = ($this->publish)();
    $response = $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.ready', true);
    $this->postJson($this->path.'/run', $run)->assertOk()->assertExactJson($response->json());
    expect($this->agents->calls)->toHaveCount(1)->and($this->agents->calls[0]['node'])->toBe('dev');
    $result = $response->json('data');
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'published', 'name' => 'caddy-publish', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('rejects a Node override and changed Gateway configuration before contacting either Node', function (): void {
    $run = ($this->publish)();
    $this->postJson($this->path.'/run', [...$run, 'node_id' => $this->node->id + 1])->assertUnprocessable();
    $this->postJson($this->path.'/run', [...$run, 'input' => [...$run['input'], 'content' => 'unadmitted.example.test {}']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
});

it('rejects unlisted commands, transports, malformed inputs and changed reasons', function (): void {
    foreach ([
        [...$this->prepare, 'name' => 'route.instance-runtime'],
        [...$this->prepare, 'transport' => 'remote_program', 'name' => 'route.publish-dns'],
        [...$this->prepare, 'input' => ['reason' => 'different']],
        [...$this->prepare, 'input' => ['reason' => 'dev proof', 'script' => 'anything']],
    ] as $run) {
        $this->postJson($this->path.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    }
    expect($this->agents->calls)->toBe([]);
});

it('refuses bare completion and a forged successful publication step', function (): void {
    $result = ['result' => 'published', 'ready' => true];
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'forged', 'name' => 'caddy-publish', 'status' => 'succeeded', 'result' => $result])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('keeps execution Services-only', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path.'/run', $this->prepare)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('drops undeclared receipt fields and redacts secret-bearing step output', function (): void {
    $this->agents->answer('caddy.publish', ['result' => 'published', 'password' => 'route-private-password']);
    $run = ($this->publish)();
    $this->postJson($this->path.'/run', $run)->assertOk()->assertExactJson(['data' => ['result' => 'published', 'ready' => true], 'meta' => ['request_id' => $this->app['request']->attributes->get('orbit.request_id')]]);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'output', 'name' => 'prepare', 'status' => 'running', 'output' => 'Authorization: Bearer route-private-token'])->assertOk();
    expect(json_encode($this->operation->fresh()->state))->not->toContain('route-private-password', 'route-private-token');
});

it('withdraws a shared PHP pool even when no Route exists', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = \App\Models\Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'root' => 'public']);
    $instance = \App\Models\Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'unrouted', 'checkout_path' => '/srv/proof/unrouted', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => \App\Domain\Instances\InstanceState::SourceResolved]);
    $removal = \App\Models\InstanceRemoval::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name, 'force' => false, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $removal->members()->create(['position' => 0, 'instance_id' => $instance->id, 'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => $instance->name, 'environment' => 'development', 'runtime_published' => false, 'root' => 'public', 'branch' => 'main', 'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit, 'repository_identity' => $project->repository_identity, 'common_repository_path' => '/srv/proof/unrouted/.git', 'source_identity' => 'proof-source', 'source_layout' => $instance->source_layout, 'checkout_path' => $instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('a', 64)]);
    $instance->update(['status' => \App\Domain\Instances\InstanceState::Removing]);
    $this->operation->update(['kind' => 'route.withdraw_instance', 'target_type' => 'instance_routes', 'target_id' => $instance->id, 'input' => ['instance_id' => $instance->id]]);
    $this->agents->answer('php.converge-shared-pools', ['changed' => true, 'missing_pools' => []]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', 'key' => 'instance-runtime-prepare', 'transport' => 'gateway', 'name' => 'route.instance-runtime', 'input' => ['instance_id' => $instance->id, 'action' => 'prepare']])->assertOk();
    $descriptors = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors');
    expect($descriptors[1]['name'])->toBe('php.converge-shared-pools');
    $run = ['worker' => 'worker', ...array_intersect_key($descriptors[1], array_flip(['key', 'transport', 'name', 'input']))];
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.withdrawn', true);
    expect($this->agents->calls)->toHaveCount(1)->and((array) $this->agents->calls[0]['input']['configurations'])->toBe([]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', ...array_intersect_key($descriptors[2], array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->assertJsonPath('data.ready', true);
});
