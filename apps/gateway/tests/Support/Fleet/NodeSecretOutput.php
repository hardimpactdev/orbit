<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeOperationAgentInstaller;
use App\Models\Node;
use Tests\Support\Fleet\FleetFixtures;

function nodeSecretOutput($test): array
{
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'node.converge' => require app_path('Domain/Nodes/node-converge-kind.php')]));
    $gateway = FleetFixtures::node('hash-gateway', [RoleName::Gateway]);
    $services = FleetFixtures::node('hash-services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $node = FleetFixtures::node('hash-owner');
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $pin = app(AgentDelivery::class)->pin($node);
    $agent = ['secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
        'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $node->id],
        'configuration' => 'gateway_url = "https://gateway.orbit"', 'certificate' => 'public certificate', 'unit' => 'public unit', 'checkouts' => []];
    $operation = app(StartOperationAction::class)->execute('node.converge', 'node', $node->id, $node->id,
        ['force_artifacts' => false, 'first_visit' => false, '_footprint' => ['node_id' => $node->id, 'agent_pin' => $pin,
            'artifacts' => [['name' => 'agent', 'digest' => 'agent-digest', 'required' => true, 'repair' => false]],
            'payloads' => ['agent' => $agent], 'children' => [], 'recorded' => []]], 'test', null);
    $operation->update(['state' => [...$operation->state, 'node_footprint_prepared' => true, 'child_intents' => []]]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('installEffect')->once()->withArgs(function (Node $target, string $selected, array $run) use ($node, $pin): bool {
        expect(EffectReceiptMetadata::fromRun($run)->envelope()['secret_outputs'])->toBe(['secret_hash']);

        return $target->id === $node->id && $selected === $pin;
    })->andReturn(true);
    app()->instance(NodeOperationAgentInstaller::class, $installer);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.converge', 'worker' => 'hash-worker'])->assertOk();
    $path = '/api/v1/operation-executor/'.$operation->id;
    $test->postJson($path.'/run', ['worker' => 'hash-worker', 'key' => 'footprint-agent-install',
        'transport' => 'node-agent', 'name' => 'agent.install', 'input' => ['pin' => $pin]])->assertOk();
    $test->postJson($path.'/run', ['worker' => 'hash-worker', 'key' => 'prepare-agent-configuration',
        'transport' => 'gateway', 'name' => 'node.prepare-agent-configuration', 'input' => (object) []])->assertOk();

    return [$node, $operation, $path, ['worker' => 'hash-worker', 'key' => 'footprint-agent',
        'transport' => 'agent', 'name' => 'agent.configure', 'input' => [...$agent, 'binary_changed' => true]]];
}
