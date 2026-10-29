<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Routes\RouteProjectionPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->node = Node::query()->create(['name' => 'metrics-child', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.20', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->assignment = $this->node->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $plan = app(RouteProjectionPlan::class)->withMetrics(['node_ids' => [$this->node->id], 'stages' => []]);
    $this->parent = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => 77,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => [],
        'state' => ['route_plan' => $plan, 'route_active_stage' => 'metrics-reconciled']]);
});

it('admits the existing Route hook once on its Metrics serving Node with a captured fleet', function (): void {
    $execution = app(RouteParentExecution::class);
    $intents = $execution->childContext($this->parent)['children'];
    expect($intents)->toBe([['key' => 'metrics-reconciled.fleet', 'kind' => 'metrics.reconcile', 'node_id' => $this->node->id, 'input' => []]]);
    expect(Operation::query()->count())->toBe(1);

    $first = DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => 'metrics-reconciled.fleet']));
    $child = $first['operation'];
    $replay = DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => 'metrics-reconciled.fleet']));

    expect($first['created'])->toBeTrue()->and($replay['created'])->toBeFalse()->and($replay['operation']->id)->toBe($child->id);
    expect($child->node_id)->toBe($this->node->id)->and($child->status)->toBe(OperationStatus::Queued)
        ->and($child->input['assignment_id'])->toBe($this->assignment->id)->and($child->input['fleet_node_ids'])->toBe([$this->node->id]);
    expect($child->input['fleet'][0])->toMatchArray(['node_id' => $this->node->id, 'selected' => true, 'address' => '10.44.0.20']);
    $before = $child->input;
    $this->node->update(['name' => 'changed-after-admission']);
    expect($child->fresh()->input)->toBe($before);
    expect(app(MetricsFleetReconciler::class))->toBeInstanceOf(NativeMetricsFleetReconciler::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('refuses a captured hook if the active assignment moved or became ambiguous', function (string $case): void {
    $other = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.21']);
    if ($case === 'moved') {
        $this->assignment->update(['node_id' => $other->id]);
    } else {
        $other->roles()->create(['role' => 'metrics', 'status' => 'active']);
    }

    expect(fn () => DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => 'metrics-reconciled.fleet'])))
        ->toThrow(ResourceOperationException::class);

    expect($this->parent->children()->count())->toBe(0)->and(Operation::query()->count())->toBe(1);
})->with(['moved', 'ambiguous']);

it('refuses caller-supplied reconcile configuration or another Node', function (string $case): void {
    $intent = ['key' => 'metrics-reconciled.fleet', 'kind' => 'metrics.reconcile', 'node_id' => $this->node->id,
        'input' => $case === 'input' ? ['fleet' => []] : []];
    if ($case === 'node') {
        $intent['node_id']++;
    }

    expect(fn () => app(MetricsReconcileAdmission::class)->admit($this->parent, $intent))->toThrow(ResourceOperationException::class);

    expect(Operation::query()->count())->toBe(1);
})->with(['input', 'node']);

it('requires actual child proof before the final receipt and redacts undeclared result data', function (): void {
    $child = DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => 'metrics-reconciled.fleet']))['operation'];
    $run = ['worker' => 'metrics-proof', 'key' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt', 'input' => ['backend' => 'active']];
    $contract = app(OperationExecutionContract::class);
    $contract->authorizeRun($child, $run);
    expect(fn () => app(MetricsFleetExecution::class)->beforeRun($child, $run))->toThrow(ResourceOperationException::class);
    expect(fn () => $contract->assertCompletion($child, ['backend' => 'active']))->toThrow(ResourceOperationException::class);
    expect(fn () => $contract->authorizeRun($child, [...$run, 'name' => 'node.remove']))->toThrow(ResourceOperationException::class);
    expect(fn () => $contract->authorizeRun($child, [...$run, 'input' => ['backend' => 'absent']]))->toThrow(ResourceOperationException::class);
    expect($contract->result($child, ['backend' => 'active', 'password' => 'sentinel', 'configuration' => 'secret']))->toBe(['backend' => 'active']);
});

it('hydrates runtime credentials only for the exact Metrics child and refuses raw or foreign references', function (): void {
    $manager = Mockery::mock(MetricsCredentialManager::class);
    $manager->shouldReceive('passwordForConvergence')->once()->withArgs(fn (Node $node): bool => $node->id === $this->node->id)->andReturn('grafana-secret-sentinel');
    app()->instance(MetricsCredentialManager::class, $manager);
    $operation = new Operation(['kind' => 'metrics.runtime_converge', 'node_id' => $this->node->id, 'target_type' => 'metrics_effect', 'target_id' => $this->node->id,
        'input' => ['assignment_id' => $this->assignment->id, 'targets' => [['name' => $this->node->name, 'address' => $this->node->wireguard_ip]], 'services' => []]]);
    $secrets = app(OperationSecrets::class);
    $reference = ['$secret' => 'metrics.runtime', 'id' => $this->node->id];
    foreach (['raw-secret', [...$reference, 'id' => $this->node->id + 1]] as $invalid) {
        expect(fn () => $secrets->hydrate($operation, 'metrics-effect', ['action' => $invalid], ['action' => 'metrics.runtime']))->toThrow(ResourceOperationException::class);
    }

    $hydrated = $secrets->hydrate($operation, 'metrics-effect', ['action' => $reference], ['action' => 'metrics.runtime']);

    expect($hydrated['input']['action']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe('grafana-secret-sentinel');
    expect($hydrated['values'])->toContain('grafana-secret-sentinel');
    expect(json_encode($operation->getAttributes()))->not->toContain('grafana-secret-sentinel');
    expect(app(OperationExecutionContract::class)->result($operation, ['changed' => true, 'configuration' => 'grafana-secret-sentinel']))->toBe(['changed' => true]);
});
