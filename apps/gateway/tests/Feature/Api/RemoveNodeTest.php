<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleName;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->caller = FleetFixtures::node('remove-api-gateway', [RoleName::Gateway]);
    $this->target = FleetFixtures::node('remove-api-target');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $runtime = Mockery::mock(NodeAgentRuntime::class);
    $runtime->shouldNotReceive('remove');
    app()->instance(NodeAgentRuntime::class, $runtime);
});

it('returns an admitted removal Operation while retaining the Node and its Metrics evidence', function (): void {
    $metrics = FleetFixtures::node('remove-api-metrics', [RoleName::Metrics]);
    $response = $this->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'node.remove')->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.target_id', $this->target->id)->assertJsonPath('data.result', null);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe($this->target->id);
    expect($operation->state['removal_intent']['offline'])->toBeFalse();
    $steps = $operation->input['_node_plan'];
    $retire = array_find($steps, static fn (array $step): bool => $step['key'] === 'remove-metrics');
    expect($retire)->toMatchArray(['kind' => 'metrics.reconcile', 'node_id' => $metrics->id, 'input' => [], 'optional' => false]);
    expect($steps[0]['key'])->toBe('remove-begin');
    expect(array_search('remove-metrics', array_column($steps, 'key'), true))->toBeLessThan(array_search('remove-agent', array_column($steps, 'key'), true));
    $this->assertDatabaseHas('nodes', ['id' => $this->target->id, 'status' => 'active']);
    expect(Operation::query()->whereNotNull('parent_operation_id')->count())->toBe(0);
});

it('replays the exact public removal admission without duplicate Operations', function (): void {
    $headers = ['Idempotency-Key' => 'public-remove-once'];
    $first = $this->withHeaders($headers)->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertAccepted();
    $this->withHeaders($headers)->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertAccepted()
        ->assertJsonPath('data.id', $first->json('data.id'));
    expect(Operation::query()->where('kind', 'node.remove')->count())->toBe(1);
    $this->assertModelExists($this->target);
});

it('refuses concurrent changes under the removal Node hold', function (): void {
    $this->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertAccepted();
    $this->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertConflict()
        ->assertJsonPath('error.code', 'operation.target_busy');
    $this->assertModelExists($this->target);
});

it('refuses protected Nodes and existing roles before admitting removal', function (string $target, string $error): void {
    $node = match ($target) {
        'self' => $this->caller,
        'vpn' => FleetFixtures::node('remove-api-vpn', [RoleName::Vpn]),
        default => FleetFixtures::node('remove-api-role', [RoleName::AppDev]),
    };
    $this->deleteJson('/api/v1/nodes/'.$node->id, ['force' => true])->assertConflict()->assertJsonPath('error.code', $error);
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
})->with([['self', 'node.self_removal_forbidden'], ['vpn', 'node.vpn_removal_forbidden'], ['role', 'node.has_roles']]);

it('records actual reachability separately from the offline request', function (bool $unreachable): void {
    $probe = Mockery::mock(NodeReachabilityProbe::class);
    $probe->shouldReceive('degradation')->once()->andReturn($unreachable ? ExporterDegradationReason::Unreachable : null);
    app()->instance(NodeReachabilityProbe::class, $probe);
    $response = $this->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true, 'offline' => true])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input['offline'])->toBeTrue();
    expect($operation->state['removal_intent']['offline'])->toBe($unreachable);
    $this->assertModelExists($this->target);
})->with([false, true]);

it('keeps executor Nodes outside the Swamp removal path', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->target->id]]);
    $this->deleteJson('/api/v1/nodes/'.$this->target->id, ['force' => true])->assertConflict()
        ->assertJsonPath('error.code', 'node.executor_dependency');
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($this->target);
});

it('refuses malformed removal bodies before admission', function (array $body): void {
    $this->deleteJson('/api/v1/nodes/'.$this->target->id, $body)->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0);
})->with([[['offline' => 'true']], [['offline' => true]], [['force' => 1]], [['deleted' => true]]]);
