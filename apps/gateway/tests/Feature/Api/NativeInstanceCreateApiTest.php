<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture-shared-token']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-create', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $this->node = Node::query()->create(['name' => 'create-dev', 'user' => 'orbit', 'platform' => 'linux', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG',
        'architecture' => 'amd64', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9',
        'status' => 'active', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->project = Project::query()->create(['name' => 'Native create', 'slug' => 'native-create',
        'type' => 'node-package', 'root' => '.', 'repository_url' => 'https://github.com/example/native-create.git',
        'default_branch' => 'main']);
    $this->payload = ['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof'];
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    Queue::fake();
});

it('records one native root with source environment and setup steps before any Node effect', function (): void {
    $this->withHeader('Idempotency-Key', 'native-create-proof');
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.create')->assertJsonPath('data.status', 'running')->json('data.id');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->assertJsonPath('data.id', $id);

    $instance = Instance::query()->sole();
    $operation = Operation::query()->sole();
    expect($instance->status)->toBe(InstanceState::Reserved)
        ->and($instance->checkout_path)->toBe('/srv/apps/native-create/proof')
        ->and($operation->input['participant_node_ids'])->toBe([$this->node->id])
        ->and($operation->input['direct_provisioning']['setup']['execution']['user'])->toBe('orbit')
        ->and($operation->input['direct_provisioning'])->not->toHaveKey('agent_sha256')
        ->and($this->socket->sent)->toHaveCount(2)
        ->and($this->socket->sent[1]['payload']['workflowIdOrName'])->toBe('operation-instance-create')
        ->and(Route::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('freezes the selected default source seed in the first native admission', function (): void {
    $seed = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'default', 'checkout_path' => '/srv/apps/native-create/default', 'status' => 'active',
        'branch' => 'main', 'seed_path' => '/srv/apps/native-create/default', 'seed_commit' => str_repeat('a', 40)]);

    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $input = Operation::query()->findOrFail($id)->input;
    expect($input['seed_repository'])->toBe($seed->checkout_path)
        ->and($input['seed_commit'])->toBe($seed->seed_commit)
        ->and($input['direct_provisioning']['source']['seed_repository'])->toBe($seed->checkout_path)
        ->and($input['direct_provisioning']['setup']['execution']['seed_path'])->toBe($seed->seed_path)
        ->and($input['direct_provisioning']['setup']['execution']['seed_commit'])->toBe($seed->seed_commit);
});

it('refuses immutable placement changes on retry', function (): void {
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/instances', [...$this->payload, 'branch' => 'other'])->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('refuses unavailable publication before reserving source or admitting work', function (): void {
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $this->postJson('/api/v1/instances', $this->payload)->assertStatus(503)
        ->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Instance::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0)
        ->and($this->socket->sent)->toBe([]);
});

it('requires owning Node access before creating an Instance', function (): void {
    $other = Node::query()->create(['name' => 'outside', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.18', 'wireguard_ip' => '10.44.0.18', 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->postJson('/api/v1/instances', $this->payload)->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    expect(Instance::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('rejects a managed checkout overlap before starting a native root', function (): void {
    Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'existing', 'checkout_path' => '/srv/apps/native-create/proof/nested', 'status' => 'active']);
    $this->postJson('/api/v1/instances', $this->payload)->assertConflict()->assertJsonPath('error.code', 'instance.path_taken');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
});
