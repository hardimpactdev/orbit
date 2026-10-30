<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Fleet\FleetFixtures;

it('admits one idempotent footprint Operation without reading or changing the Node', function (): void {
    Queue::fake();
    $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('dev');
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'converge-proof');
    $first = $this->postJson("/api/v1/nodes/{$node->id}/converge", ['force' => true])->assertAccepted();
    $this->postJson("/api/v1/nodes/{$node->id}/converge", ['force' => true])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('node.converge')->and($operation->node_id)->toBe($node->id)
        ->and($operation->input)->toBe(['force_artifacts' => true, 'first_visit' => false])
        ->and($node->refresh()->status)->toBe(LifecycleStatus::Active);
    Queue::assertNothingPushed();
});

it('refuses an unmanaged footprint target before admission', function (): void {
    $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('unmanaged', managed: false);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $this->postJson("/api/v1/nodes/{$node->id}/converge")->assertUnprocessable()->assertJsonPath('error.code', 'node.converge_unsupported');
    expect(Operation::query()->exists())->toBeFalse();
});

it('registers the footprint with protected outputs and optional child completion', function (): void {
    expect(app(OperationKinds::class)->executor('node.converge'))->toBe('swamp');
});

it('admits protected enrollment for an executor Node asynchronously and redacts durable intent', function (): void {
    Queue::fake();
    $gateway = FleetFixtures::node('worker-enrollment-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('worker-enrollment-target');
    config(['swamp_workers.nodes' => [$node->id], 'operation_executor.worker_node_ids' => [(string) $node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'protected-worker-enrollment');
    $credentials = ['enrollment_token' => 'orbit-node-'.$node->id.'.privateEnrollmentSentinel', 'server_token' => 'orbit-node-'.$node->id.'-access.privateServerSentinel'];
    $response = $this->postJson("/api/v1/nodes/{$node->id}/converge", ['worker_credentials' => $credentials])->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input)->toHaveKey('_worker_enrollment');
    expect(json_encode($operation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
    expect($response->getContent())->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
    expect(app(NodeWorkerEnrollment::class)->resolve($operation, $node->id, 'footprint-agent', 'worker_credentials')->value)->toBe($credentials);
    $this->postJson("/api/v1/nodes/{$node->id}/converge", ['worker_credentials' => $credentials])->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $this->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.swamp_worker_selected', true);
    config(['swamp_workers.nodes' => []]);
    $this->getJson("/api/v1/nodes/{$node->id}")->assertOk()->assertJsonPath('data.swamp_worker_selected', false);
    $this->withHeader('Idempotency-Key', 'unselected-worker-enrollment')->postJson("/api/v1/nodes/{$node->id}/converge", ['worker_credentials' => $credentials])->assertUnprocessable()->assertJsonPath('error.code', 'swamp.node_worker_not_ready');
    $this->assertDatabaseCount('operations', 1);
});
