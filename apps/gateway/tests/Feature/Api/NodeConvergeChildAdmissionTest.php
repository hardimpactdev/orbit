<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\PrepareNodeFootprintCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

final readonly class NodeConvergeParentFixture implements OperationFamilyChildren, OperationFamilyExecution
{
    public function childContext(Operation $parent): array
    {
        return ['intent' => [], 'children' => $parent->state['child_intents']];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}

it('stamps preparation before child admission and preserves input through execution and replay', function (): void {
    $gateway = FleetFixtures::node('child-gateway', [RoleName::Gateway]);
    $services = FleetFixtures::node('child-services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $node = FleetFixtures::node('child-dev');
    $artifacts = [new FakeFootprintArtifact('tmpfiles', 'current')];
    $verification = Mockery::mock(FleetNodeVerification::class);
    $verification->shouldReceive('baseline')->once()->andReturn([]);
    app()->instance(PrepareNodeFootprintCommand::class, new PrepareNodeFootprintCommand(
        new NodeFootprint(app(AgentDelivery::class), $artifacts), $verification, app(NodeAgentSshExecutor::class),
        app(PeerDnsPolicy::class), app(PeerDnsInspector::class), app(OperationStore::class), $artifacts,
    ));
    $definitions = OperationKinds::definitions();
    $definitions['test.node-parent'] = ['executor' => 'swamp', 'execution' => NodeConvergeParentFixture::class,
        'runs' => [], 'children' => [['kind' => 'node.converge', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['node.converge']], 'result_fields' => ['error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $parent = app(StartOperationAction::class)->execute('test.node-parent', 'node', $node->id, $node->id, [], 'test', null);
    $parent->update(['state' => [...$parent->state, 'child_intents' => [['key' => 'footprint', 'kind' => 'node.converge',
        'node_id' => $node->id, 'input' => ['force_artifacts' => false, 'first_visit' => false]]]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent'])->assertOk();
    $parentPath = '/api/v1/operation-executor/'.$parent->id;
    $id = $this->postJson($parentPath.'/children', ['worker' => 'parent', 'key' => 'footprint'])->assertAccepted()->json('data.id');
    $this->postJson($parentPath.'/children', ['worker' => 'parent', 'key' => 'footprint'])->assertOk()->assertJsonPath('data.id', $id);
    $child = Operation::query()->findOrFail($id);
    $admitted = $child->input;
    expect($admitted['_footprint']['artifacts'][0]['name'])->toBe('tmpfiles');
    $this->postJson($parentPath.'/wait', ['worker' => 'parent', 'keys' => ['footprint']])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.converge', 'worker' => 'child', 'operation_id' => $id])->assertOk();
    $path = '/api/v1/operation-executor/'.$id;
    $this->postJson($path.'/run', ['worker' => 'child', 'key' => 'prepare-footprint', 'transport' => 'gateway',
        'name' => 'node.prepare-footprint', 'input' => ['force_artifacts' => false, 'first_visit' => false]])->assertOk();
    expect($child->fresh()->input)->toBe($admitted);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('node.publish-tmpfiles', ['changed' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($path.'/run', ['worker' => 'child', 'key' => 'footprint-tmpfiles', 'transport' => 'agent',
        'name' => 'node.publish-tmpfiles', 'input' => $admitted['_footprint']['payloads']['tmpfiles']])->assertOk();
    $result = $this->postJson($path.'/run', ['worker' => 'child', 'key' => 'footprint-result', 'transport' => 'gateway',
        'name' => 'node.footprint-result', 'input' => (object) []])->assertOk()->json('data');
    $this->postJson($path.'/step', ['worker' => 'child', 'key' => 'result', 'name' => 'footprint-result',
        'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'child', 'status' => 'succeeded', 'result' => $result])->assertOk();
    app(OperationChildren::class)->verify($parent->fresh(), $child->fresh());
    $this->getJson($parentPath.'/children')->assertOk()->assertJsonPath('data.0.status', 'succeeded');
    expect($child->fresh()->input)->toBe($admitted)->and($parent->fresh()->lock_key)->toBe('node:'.$node->id);
});
