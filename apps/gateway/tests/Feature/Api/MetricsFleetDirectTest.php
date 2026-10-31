<?php

declare(strict_types=1);

use App\Domain\Metrics\AdmitMetricsFleetAction;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetProjection;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'fleet-direct-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->node = Node::query()->forceCreate(['id' => 9, 'name' => 'metrics', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'fixture']);
    $this->node->roles()->create(['role' => 'metrics', 'status' => 'active']);
    config(['swamp_workers.nodes' => [9]]);
    $this->plan = app(MetricsFleetCapture::class)->capture();
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => 9, 'node_id' => 9, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['swamp_run_id' => $this->run],
        'input' => ['participant_node_ids' => [9], 'metrics_fleet' => $this->plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => 9]]]);
    $this->credentials = Mockery::mock(MetricsCredentialManager::class);
    app()->instance(MetricsCredentialManager::class, $this->credentials);
    $this->result = ['ready' => true, 'missing_worker_ids' => [], 'verified_node_ids' => [9], 'restored_node_ids' => [], 'unknown_node_ids' => [], 'runtime_committed' => true];
    $this->completion = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00Z', 'finished_at' => '2026-10-31T03:00:01Z', 'error_code' => null, 'result' => $this->result];
});
afterEach(function (): void {
    unlink($this->manifest);
});

it('hydrates only the declared owned runtime through the real shared reader without persisting bytes', function (): void {
    $this->credentials->shouldReceive('passwordForConvergence')->once()->withArgs(fn (Node $node): bool => $node->id === 9)->andReturn('fleet-private-sentinel');
    $before = $this->operation->fresh()->getAttributes();
    $material = app(DirectOperationPrivateMaterial::class)->read($this->operation);
    expect($material['fleet_runtime']['runtime']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe('fleet-private-sentinel');
    expect($material['fleet_runtime']['verify'])->toBe(['operation' => 'verify', 'password' => 'fleet-private-sentinel']);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain('fleet-private-sentinel');
    expect(app(MetricsFleetProjection::class)->input($this->operation))->toBe(['participant_node_ids' => [9], 'metrics_fleet' => $this->plan]);
});
it('refuses foreign ownership unbound roots terminal roots and workerless captures before reading credentials', function (string $change): void {
    $this->credentials->shouldNotReceive('passwordForConvergence');
    if ($change === 'owner') {
        $this->operation->update(['target_id' => 13]);
    } elseif ($change === 'unbound') {
        $this->operation->update(['state' => []]);
    } elseif ($change === 'terminal') {
        $this->operation->update(['status' => OperationStatus::Failed]);
    } else {
        $plan = $this->plan;
        $plan['fleet'][0]['worker'] = false;
        unset($plan['digest']);
        $plan['digest'] = MetricsFleetCapture::digest($plan);
        $this->operation->update(['input' => [...$this->operation->input, 'metrics_fleet' => $plan]]);
    }
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation))->toThrow(ResourceOperationException::class);
})->with(['owner', 'unbound', 'terminal', 'workerless']);
it('projects one complete root and accepts only its exact terminal replay', function (): void {
    $projection = app(MetricsFleetProjection::class);
    $done = $projection->apply($this->operation, $this->completion);
    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result)->toBe($this->result);
    $sequence = $done->sequence;
    expect($projection->apply($done, $this->completion)->sequence)->toBe($sequence);
    expect(Operation::query()->count())->toBe(1);
});
it('records the native result without reconstructing its fleet proof in PHP', function (): void {
    $result = [...$this->result, 'verified_node_ids' => [9, 9], 'runtime_committed' => false];
    $done = app(MetricsFleetProjection::class)->apply($this->operation, [...$this->completion, 'result' => $result]);
    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result)->toBe($result);
});
it('projects an explicit workerless failure without pretending reconciliation succeeded', function (): void {
    $plan = $this->plan;
    $plan['fleet'][0]['worker'] = false;
    unset($plan['digest']);
    $plan['digest'] = MetricsFleetCapture::digest($plan);
    $this->operation->update(['input' => [...$this->operation->input, 'metrics_fleet' => $plan]]);
    $result = ['ready' => false, 'error_code' => 'metrics.no_worker', 'missing_worker_ids' => [9], 'verified_node_ids' => [], 'restored_node_ids' => [], 'unknown_node_ids' => [], 'runtime_committed' => false];
    $done = app(MetricsFleetProjection::class)->apply($this->operation, [...$this->completion, 'status' => 'failed', 'error_code' => 'metrics.no_worker', 'result' => $result]);
    expect($done->status)->toBe(OperationStatus::Failed)->and($done->error_code)->toBe('metrics.no_worker')->and($done->result)->toBe($result);
});

it('refuses a changed desired runtime before reading its credential', function (): void {
    $this->credentials->shouldNotReceive('passwordForConvergence');
    $this->node->update(['name' => 'changed-after-capture']);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation))->toThrow(ResourceOperationException::class);
});

it('records and starts one native root without executing or awaiting the fleet in PHP', function (): void {
    $this->operation->update(['status' => OperationStatus::Cancelled]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-metrics-fleet', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'scoped-fixture']);
    $operation = app(AdmitMetricsFleetAction::class)->execute();
    expect($operation->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBe($this->run);
    expect($socket->sent)->toHaveCount(1);
});

it('serves only fresh admitted Caddy public input to a foreign Node and keeps mutations private', function (): void {
    $gateway = $this->markAsGateway(Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']));
    $participant = Node::query()->forceCreate(['id' => 13, 'name' => 'shark', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.13', 'wireguard_ip' => '10.44.0.13']);
    $participant->accessibleNodes()->attach($gateway);
    $plan = $this->plan;
    $plan['fleet'][] = [...$plan['fleet'][0], 'node_id' => 13, 'caddy_managed' => true];
    $this->operation->update(['input' => [...$this->operation->input, 'participant_node_ids' => [9, 13], 'metrics_fleet' => $plan], 'result' => ['root_only' => true]]);
    $this->withServerVariables(['REMOTE_ADDR' => $participant->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.node_id', 9)->assertJsonPath('data.input.caddy_publication.node_id', 13)
        ->assertJsonMissingPath('data.input.metrics_fleet')->assertJsonMissingPath('data.result');
    $this->getJson($path.'?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict();
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertForbidden();
    $this->postJson($path.'/result', [...$this->completion, 'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z'])->assertForbidden();
    $this->operation->update(['input' => [...$this->operation->input, 'participant_node_ids' => [9]]]);
    $this->getJson($path.'?run_id='.$this->run)->assertForbidden();
});

it('records a terminal native admission failure when submission is unconfigured', function (): void {
    $this->operation->update(['kind' => 'metrics.credentials_reset', 'status' => OperationStatus::Running]);
    expect(fn () => app(AdmitMetricsFleetAction::class)->execute())->toThrow(ResourceOperationException::class, 'The native workflow start is not configured.');
    expect(Operation::query()->count())->toBe(2);
    expect(Operation::query()->latest('id')->firstOrFail()->status)->toBe(OperationStatus::Failed);
});
