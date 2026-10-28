<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
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
