<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Node;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FakeFootprintArtifact;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->create(['ssh_host_fingerprint' => 'SHA256:test', 'name' => 'canary', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->snapshot = ['schema_version' => 1, 'node_id' => $this->node->id, 'agent_sha256' => str_repeat('a', 64),
        'artifacts' => [['name' => 'agent', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false],
            ['name' => 'tmpfiles', 'digest' => str_repeat('b', 64), 'required' => true, 'repair' => false]],
        'payloads' => [], 'network' => null, 'refusal' => null, 'force_artifacts' => false, 'first_visit' => false];
    $this->operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'input' => ['_converge' => $this->snapshot, 'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $this->node->id]],
        'state' => ['node_converge_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/result';
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['footprint_proven' => true, 'artifacts' => [
            ['name' => 'agent', 'status' => 'unchanged'], ['name' => 'tmpfiles', 'status' => 'applied'],
        ]]];
});

it('binds the native start after commit with no SSH during pure admission', function (): void {
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    $agents->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $agents);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-converge-direct', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.direct_writers' => ['node.converge' => 'direct'], 'swamp.run_token' => 'fake.token']);
    DB::beginTransaction();
    $op = app(StartNodeConvergeAction::class)->execute($this->node, true);
    $path = '/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run;
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    expect($socket->sent)->toBe([]);
    DB::commit();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-node-converge-direct', 'inputs' => [
        'operation_id' => $op->id, 'kind' => 'node.converge', 'node_id' => $this->node->id,
    ], 'runtimeTags' => ['orbit_start_key' => 'operation:'.$op->id]]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.input.node_id', $this->node->id)->assertJsonMissingPath('data.input.secret_hash');
    expect($op->fresh()->lock_key)->toBeNull();
    expect($op->fresh()->worker)->toBeNull();
    expect($op->fresh()->attempts)->toBe(0);
    expect(Operation::query()->where('parent_operation_id', $op->id)->exists())->toBeFalse();
});

it('keeps parent admissions and the default writer unchanged until integration', function (): void {
    $direct = app(DirectOperation::class);
    expect($direct->writer('node.converge', [], $this->node->id))->toBe('polled');
    config(['swamp.direct_writers' => ['node.converge' => 'direct']]);
    expect($direct->writer('node.converge', [], $this->node->id))->toBe('direct');
    app(OperationIdempotency::class)->parent = $this->operation;
    expect($direct->writer('node.converge', [], $this->node->id))->toBe('polled');
});

it('projects bounded artifact digests once without changing roles or the agent hash', function (): void {
    $this->node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['other' => 'retained']]);
    $this->postJson($this->path, $this->body)->assertOk();
    $record = NodeFootprint::query()->where('node_id', $this->node->id)->firstOrFail();
    expect($record->artifacts)->toBe(['agent' => str_repeat('a', 64), 'tmpfiles' => str_repeat('b', 64)]);
    $before = $record->updated_at;
    $this->travel(1)->minutes();
    $this->postJson($this->path, $this->body)->assertOk();
    expect($record->fresh()->updated_at->equalTo($before))->toBeTrue();
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
    expect($this->node->roles()->sole()->role)->toBe(RoleName::AppDev);
});

it('retains unreached artifacts and removes only a known failed digest', function (): void {
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['agent' => 'old-agent', 'tmpfiles' => 'old-tmpfiles', 'other' => 'retained']]);
    $body = [...$this->body, 'status' => 'failed', 'error_code' => 'agent.secret_mismatch', 'result' => [
        'footprint_proven' => false, 'error_code' => 'agent.secret_mismatch', 'failed_artifact' => 'agent', 'artifacts' => [],
    ]];
    $this->postJson($this->path, $body)->assertOk();
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts)->toBe(['other' => 'retained', 'tmpfiles' => 'old-tmpfiles']);
});

it('rejects unproven duplicate foreign secret-bearing and contradictory results', function (array $result): void {
    $this->postJson($this->path, [...$this->body, 'result' => $result])->assertUnprocessable();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->exists())->toBeFalse();
})->with([
    'missing proof' => [[]],
    'missing artifact' => [['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'applied']]]],
    'duplicate' => [['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'applied'], ['name' => 'agent', 'status' => 'applied']]]],
    'foreign' => [['footprint_proven' => true, 'artifacts' => [['name' => 'foreign', 'status' => 'applied']]]],
    'secret' => [['footprint_proven' => true, 'secret_hash' => 'do-not-project', 'artifacts' => []]],
    'wrong skip' => [['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'skipped', 'reason' => 'caddy_render_refused'], ['name' => 'tmpfiles', 'status' => 'applied']]]],
    'success error' => [['footprint_proven' => true, 'error_code' => 'agent.secret_mismatch', 'artifacts' => [['name' => 'agent', 'status' => 'applied'], ['name' => 'tmpfiles', 'status' => 'applied']]]],
]);

it('refuses cross-node callers wrong runs and any family primitive', function (): void {
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertOk();
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id=532e32fe-41f9-42c2-9cf3-c70fd1396ba1')->assertConflict();
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    $this->operation->update(['node_id' => $this->gateway->id]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertForbidden();
});

it('hydrates canonical worker material only for the bound Node and never exposes it in safe input', function (): void {
    config(['swamp_workers.nodes' => [$this->node->id], 'operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    $credentials = ['enrollment_token' => 'orbit-node-'.$this->node->id.'.privateEnrollmentSentinel', 'server_token' => 'orbit-node-'.$this->node->id.'-access.privateServerSentinel'];
    $reference = app(NodeWorkerEnrollment::class)->stage($this->node, $credentials);
    $this->operation->update(['input' => [...$this->operation->input, '_worker_enrollment' => $reference,
        'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $this->node->id]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'/private-material?run_id='.$this->run)
        ->assertOk()->assertJsonPath('data.worker_credentials', $credentials)->assertJsonPath('data.secret_hash', str_repeat('c', 64));
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)
        ->assertOk()->assertJsonMissingPath('data.input.worker_credentials')->assertJsonMissingPath('data.input._worker_enrollment');
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/secret-output', ['run_id' => $this->run, 'outputs' => ['secret_hash' => str_repeat('d', 64)]])->assertUnprocessable();
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
    expect(json_encode([$this->operation->fresh()->toArray(), $this->operation->steps()->get()->toArray()]))->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
});

it('projects residue cleanup only onto unchanged admitted rows', function (): void {
    $first = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91001, 'domain' => 'first.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    $changed = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91002, 'domain' => 'changed.orbit', 'steps' => ['php'], 'attempts' => 0]);
    $snapshot = [...$this->snapshot, 'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false]],
        'payloads' => ['route-residue' => ['rows' => [$first->only(['id', 'route_id', 'domain', 'steps', 'attempts']), $changed->only(['id', 'route_id', 'domain', 'steps', 'attempts'])]]]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $changed->increment('attempts');
    $later = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91003, 'domain' => 'later.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    $this->postJson($this->path, [...$this->body, 'result' => ['footprint_proven' => true, 'artifacts' => [['name' => 'route-residue', 'status' => 'applied']]]])->assertOk();
    expect($first->fresh())->toBeNull();
    expect($changed->fresh()->attempts)->toBe(1);
    expect($later->fresh())->not->toBeNull();
});

it('counts a known residue skip once and retains its digest for the next repair', function (): void {
    $row = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91001, 'domain' => 'pending.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    $snapshot = [...$this->snapshot, 'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false]],
        'payloads' => ['route-residue' => ['rows' => [$row->only(['id', 'route_id', 'domain', 'steps', 'attempts'])]]]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $body = [...$this->body, 'result' => ['footprint_proven' => true, 'artifacts' => [['name' => 'route-residue', 'status' => 'skipped', 'reason' => 'route_residue_cleanup_failed']]]];
    $this->postJson($this->path, $body)->assertOk();
    $this->postJson($this->path, $body)->assertOk();
    expect($row->fresh()->attempts)->toBe(1);
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts)->toBe(['route-residue' => str_repeat('a', 64)]);
});

it('captures the active VPN listener even when the Gateway and VPN share one Node', function (): void {
    $this->gateway->forceFill(['architecture' => 'x86_64', 'ssh_host_fingerprint' => 'SHA256:test'])->save();
    $this->gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    $agents->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $agents);
    $snapshot = app(NodeConvergeInput::class)->capture($this->gateway->fresh(), true, false);
    expect(array_column($snapshot['artifacts'], 'name'))->toContain('private-dns');
    expect($snapshot['payloads']['private-dns']['listener']['service'])->toContain($this->gateway->wireguard_ip);
    expect(array_keys($snapshot['payloads']['private-dns']['publication']))->toBe(['records', 'catalog']);
});

it('refuses an unsupported contribution before effects even when its digest matches', function (): void {
    $artifact = new FakeFootprintArtifact('unsupported-extension', 'v1');
    $delivery = app(AgentDelivery::class);
    $footprint = new App\Domain\Fleet\NodeFootprint($delivery, [$artifact]);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => $footprint->expected($this->node)->artifacts]);

    $snapshot = (new NodeConvergeInput($footprint, $delivery, [$artifact]))->capture($this->node, false, false);

    expect($snapshot['refusal'])->toBe('operation.kind_unavailable');
    expect($artifact->applied)->toBe([]);
});
