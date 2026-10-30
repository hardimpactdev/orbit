<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $gateway = Node::query()->create(['name' => 'rename-gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.1']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-instance-lifecycle-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
    config(['swamp.run_token' => 'fixture.shared.run.token']);
    $this->node = Node::query()->create(['name' => 'rename-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($gateway);
    $project = Project::query()->create(['name' => 'Rename', 'slug' => 'rename',
        'repository_url' => 'https://github.com/example/rename.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/rename/proof', 'source_layout' => 'checkout',
        'status' => InstanceState::Active, 'branch' => 'old']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'dependency_detection.services_node_id' => null,
        'swamp.direct_writers' => ['instance.rename' => 'direct']]);
    $this->admit = function (): Operation {
        $response = $this->postJson('/api/v1/instances/'.$this->instance->id.'/rename', ['branch' => 'feature'])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));

        return $operation;
    };
    $this->body = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'result' => ['source_verified' => true, 'branch' => 'feature']];
});

it('keeps the default writer polled and stamps a direct admission without a Gateway hold', function (): void {
    config(['swamp.direct_writers' => []]);
    expect(app(DirectOperation::class)->writer('instance.rename'))->toBe('polled');
    config(['swamp.direct_writers' => ['instance.rename' => 'direct']]);
    $operation = ($this->admit)();
    expect($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0)
        ->and($operation->state['instance_lifecycle_writer'])->toBe('direct')
        ->and($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-instance-lifecycle-direct',
        'inputs' => ['operation_id' => $operation->id, 'kind' => 'instance.rename', 'node_id' => $this->node->id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id]]);
    expect($operation->state['swamp_run_id'])->toBe($this->body['run_id']);
    config(['swamp.direct_writers' => []]);
    $input = $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->body['run_id'])
        ->assertOk()->json('data.input');
    expect(array_keys($input))->toBe(['agent_sha256', 'repository_identity', 'branch', 'source'])
        ->and($input['source']['checkout'])->toBe($this->instance->checkout_path)
        ->and($input['source']['require_idle'])->toBeTrue();
});

it('refuses unsupported direct domain changes before recording intent', function (): void {
    $this->postJson('/api/v1/instances/'.$this->instance->id.'/rename', ['domain' => 'new.test'])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Operation::query()->count())->toBe(0);
});

it('projects a checked branch and replays terminal results without rewriting the Instance', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $this->postJson($path, $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect($this->instance->fresh()->branch)->toBe('feature')->and($this->instance->fresh()->branch_override)->toBe('feature');
    $sequence = $operation->fresh()->sequence;
    $this->instance->update(['branch' => 'later', 'branch_override' => 'later']);
    $this->postJson($path, $this->body)->assertOk();
    expect($operation->fresh()->sequence)->toBe($sequence)->and($this->instance->fresh()->branch)->toBe('later');
});

it('rejects an unbound run before read or projection can bind it', function (): void {
    $operation = ($this->admit)();
    $operation->update(['state' => ['instance_lifecycle_writer' => 'direct']]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->body['run_id'])->assertStatus(409)->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($path.'/result', $this->body)->assertStatus(409)->assertJsonPath('error.code', 'operation.run_unbound');
    expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($this->instance->fresh()->branch)->toBe('old');
});

it('rejects foreign Nodes and runs without changing the Instance', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->postJson($path.'/result', [...$this->body, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])
        ->assertStatus(409)->assertJsonPath('error.code', 'operation.run_mismatch');
    $other = Node::query()->create(['name' => 'other-worker', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.10']);
    $other->accessibleNodes()->attach(Node::query()->where('name', 'rename-gateway')->firstOrFail());
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->getJson($path.'?run_id='.$this->body['run_id'])->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    $this->postJson($path.'/result', $this->body)->assertForbidden();
    expect($this->instance->fresh()->branch)->toBe('old');
});

it('refuses invalid success without making a state change', function (array $result): void {
    $operation = ($this->admit)();
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [...$this->body, 'result' => $result])->assertStatus(422);
    expect($this->instance->fresh()->branch)->toBe('old')->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
})->with([
    'unverified' => [['branch' => 'feature']],
    'wrong branch' => [['source_verified' => true, 'branch' => 'other']],
    'success with refusal' => [['source_verified' => true, 'branch' => 'feature', 'error_code' => 'instance.source_busy']],
]);

it('projects failure and cancellation without changing the recorded branch', function (string $status): void {
    $operation = ($this->admit)();
    $code = $status === 'failed' ? 'instance.source_busy' : null;
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [...$this->body, 'status' => $status,
        'result' => $code === null ? [] : ['error_code' => $code], 'error_code' => $code])
        ->assertOk()->assertJsonPath('data.status', $status);
    expect($this->instance->fresh()->branch)->toBe('old');
})->with(['failed', 'cancelled']);

it('reads terminal results after the Instance has been removed without projecting it again', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->postJson($path.'/result', $this->body)->assertOk();
    $this->instance->delete();
    $this->getJson($path.'?run_id='.$this->body['run_id'])->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path.'/result', $this->body)->assertOk();
    expect(Instance::query()->find($this->instance->id))->toBeNull();
});

it('keeps inspection input pinned but refuses to update an Instance moved after admission', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $before = $this->getJson($path.'?run_id='.$this->body['run_id'])->assertOk()->json('data.input');
    $this->instance->update(['checkout_path' => '/srv/apps/rename/elsewhere']);
    expect($this->getJson($path.'?run_id='.$this->body['run_id'])->assertOk()->json('data.input'))->toBe($before);
    $this->postJson($path.'/result', $this->body)->assertStatus(409)->assertJsonPath('error.code', 'instance.lifecycle_conflict');
    expect($this->instance->fresh()->branch)->toBe('old')->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('recovers the same uncertain intent on an authorized same-key POST without a read-triggered start', function (): void {
    $this->socket->reply = static fn (array $request): array => [];
    $path = '/api/v1/instances/'.$this->instance->id.'/rename';
    $this->withHeader('Idempotency-Key', 'uncertain-rename');
    $this->postJson($path, ['branch' => 'feature'])->assertStatus(503)->assertJsonPath('error.code', 'swamp.start_uncertain');
    $operation = Operation::query()->sole();
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);

    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->body['run_id'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($path, ['branch' => 'different'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $other = Node::query()->create(['name' => 'unrelated', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.99', 'public_ssh_host' => '192.0.2.99']);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->postJson($path, ['branch' => 'feature'])->assertForbidden();
    expect($this->socket->sent)->toHaveCount(2);

    $this->socket->reply = static fn (array $request): array => [['type' => 'workflow.run.search', 'id' => $request['id'],
        'payload' => ['total' => 1, 'data' => ['results' => [[
            'workflowName' => 'operation-instance-lifecycle-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'running',
            'tags' => ['orbit_start_key' => 'operation:'.$operation->id],
            'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        ]]]]]];
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($path, ['branch' => 'feature'])->assertAccepted()->assertJsonPath('data.id', $operation->id);

    $this->assertDatabaseCount('operations', 1);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->body['run_id']);
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search', 'workflow.run.search']);
    expect($operation->fresh()->attempts)->toBe(0)->and($operation->fresh()->lock_key)->toBeNull();
});
