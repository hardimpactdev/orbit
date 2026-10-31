<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Metrics\AdmitMetricsFleetAction;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetProjection;
use App\Domain\Nodes\NodeEnrollmentPlan;
use App\Domain\Nodes\NodeRemovalPlan;
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
        'state' => ['metrics_fleet_writer' => 'direct', 'swamp_run_id' => $this->run],
        'input' => ['metrics_fleet' => $this->plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => 9]]]);
    $this->credentials = Mockery::mock(MetricsCredentialManager::class);
    app()->instance(MetricsCredentialManager::class, $this->credentials);
    $this->result = ['ready' => true, 'missing_worker_ids' => [], 'verified_node_ids' => [9], 'restored_node_ids' => [], 'unknown_node_ids' => [], 'runtime_committed' => true];
    $this->completion = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00Z', 'finished_at' => '2026-10-31T03:00:01Z', 'error_code' => null, 'result' => $this->result];
});
afterEach(function (): void {
    unlink($this->manifest);
});

it('refuses the deleted polled writer without creating another intent', function (): void {
    $before = Operation::query()->count();
    config(['swamp.direct_writers' => ['metrics.reconcile' => 'polled']]);
    expect(fn () => app(AdmitMetricsFleetAction::class)->execute([9]))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before);
});
it('hydrates only the declared owned runtime through the real shared reader without persisting bytes', function (): void {
    $this->credentials->shouldReceive('passwordForConvergence')->once()->withArgs(fn (Node $node): bool => $node->id === 9)->andReturn('fleet-private-sentinel');
    $before = $this->operation->fresh()->getAttributes();
    $material = app(DirectOperationPrivateMaterial::class)->read($this->operation);
    expect($material['fleet_runtime']['runtime']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe('fleet-private-sentinel');
    expect($material['fleet_runtime']['verify'])->toBe(['operation' => 'verify', 'password' => 'fleet-private-sentinel']);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain('fleet-private-sentinel');
    expect(app(MetricsFleetProjection::class)->input($this->operation))->toBe(['metrics_fleet' => $this->plan]);
});
it('refuses foreign ownership unbound roots terminal roots and workerless captures before reading credentials', function (string $change): void {
    $this->credentials->shouldNotReceive('passwordForConvergence');
    if ($change === 'owner') {
        $this->operation->update(['target_id' => 13]);
    } elseif ($change === 'unbound') {
        $this->operation->update(['state' => ['metrics_fleet_writer' => 'direct']]);
    } elseif ($change === 'terminal') {
        $this->operation->update(['status' => OperationStatus::Failed]);
    } elseif ($change === 'polled') {
        $this->operation->update(['state' => ['metrics_fleet_writer' => 'polled', 'swamp_run_id' => $this->run]]);
    } else {
        $plan = $this->plan;
        $plan['fleet'][0]['worker'] = false;
        unset($plan['digest']);
        $plan['digest'] = MetricsFleetCapture::digest($plan);
        $this->operation->update(['input' => [...$this->operation->input, 'metrics_fleet' => $plan]]);
    }
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation))->toThrow(ResourceOperationException::class);
})->with(['owner', 'unbound', 'terminal', 'polled', 'workerless']);
it('projects one complete root and accepts only its exact terminal replay', function (): void {
    $projection = app(MetricsFleetProjection::class);
    $done = $projection->apply($this->operation, $this->completion);
    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result)->toBe($this->result);
    $sequence = $done->sequence;
    expect($projection->apply($done, $this->completion)->sequence)->toBe($sequence);
    expect(Operation::query()->count())->toBe(1);
    expect($done->steps()->count())->toBe(1);
});
it('refuses duplicate missing unknown or restored proof as success', function (string $field): void {
    $result = $this->result;
    $result[$field] = match ($field) {
        'verified_node_ids' => [9, 9],'missing_worker_ids' => [9],'runtime_committed' => false,default => [9]
    };
    expect(fn () => app(MetricsFleetProjection::class)->apply($this->operation, [...$this->completion, 'result' => $result]))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
})->with(['verified_node_ids', 'missing_worker_ids', 'unknown_node_ids', 'restored_node_ids', 'runtime_committed']);
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
    $operation = app(AdmitMetricsFleetAction::class)->execute([9]);
    expect($operation->status)->toBe(OperationStatus::Queued)->and($operation->parent_operation_id)->toBeNull()
        ->and($operation->state['metrics_fleet_writer'])->toBe('direct')->and($operation->state['swamp_run_id'])->toBe($this->run);
    expect($socket->sent)->toHaveCount(1);
    expect($operation->children()->count())->toBe(0);
});

it('refuses retirement before removal changes state or revokes access', function (): void {
    $target = Node::query()->create(['name' => 'retiring', 'public_ssh_host' => '192.0.2.13', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.13']);
    $before = $target->fresh()->getAttributes();
    $access = Mockery::mock(MetricsAccessRevoker::class);
    $access->shouldNotReceive('revoke');
    app()->instance(MetricsAccessRevoker::class, $access);
    $count = Operation::query()->count();
    expect(fn () => app(RemoveNodeAction::class)->execute($target, $this->node))->toThrow(ResourceOperationException::class, 'Node retirement requires Metrics cleanup');
    expect($target->fresh()->getAttributes())->toBe($before);
    expect(Operation::query()->count())->toBe($count);
});
it('refuses legacy parent plans instead of emitting incompatible Metrics children', function (string $plan): void {
    $target = Node::query()->create(['name' => 'planned', 'public_ssh_host' => '192.0.2.13', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit']);
    $build = match ($plan) {
        'remove' => fn () => app(NodeRemovalPlan::class)->build($target, false),
        'restore' => fn () => app(NodeRemovalPlan::class)->restore($target),
        default => fn () => app(NodeEnrollmentPlan::class)->build($target, ['mode' => 'linux', 'roleless_operator' => false], ['route_changes' => [], 'roles' => [], 'settings_provided' => false, 'task_vm_id' => null]),
    };
    expect($build)->toThrow(ResourceOperationException::class, 'This parent requires Metrics stages');
})->with(['remove', 'restore', 'enroll']);
