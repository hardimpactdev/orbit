<?php

declare(strict_types=1);

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeAgentRuntime;

it('refuses retired Gateway bootstrap before changing state or observer authentication', function (bool $existing): void {
    if ($existing) {
        $node = Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.14',
        ]);
        $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
        $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    }
    $beforeNodes = Node::query()->get()->toArray();
    $beforeRoles = NodeRole::query()->get()->toArray();
    $commands = new FakeAgentCommandRunner;
    $observer = new FakeNodeAgentRuntime;
    app()->instance(AgentCommandRunner::class, $commands);
    app()->instance(NodeAgentRuntime::class, $observer);

    try {
        app(BootstrapGatewayAction::class)->execute(new BootstrapGatewayData(
            publicHost: 'gateway.example.test', wireguardIp: '10.44.0.2',
            wireguardSubnet: '10.44.0.0/24', wireguardEndpoint: 'gateway.example.test:51820',
            dnsServer: '10.44.0.2', domain: 'orbit',
        ));
        $this->fail('Expected Gateway bootstrap to be unavailable.');
    } catch (NodeProvisioningException $error) {
        expect($error->errorCode)->toBe('gateway.bootstrap_unavailable')
            ->and($error->step)->toBe('bootstrap')
            ->and($error->status)->toBe(422);
    }

    expect(Node::query()->get()->toArray())->toBe($beforeNodes)
        ->and(NodeRole::query()->get()->toArray())->toBe($beforeRoles)
        ->and($commands->calls)->toBe([])
        ->and($commands->installs)->toBe([])
        ->and($observer->nodeIds)->toBe([]);
    if ($existing) {
        expect(Node::query()->where('name', 'gateway')->sole()->agent_secret_hash)->toBe(str_repeat('a', 64));
    }
})->with(['new Gateway' => false, 'enrolled Gateway' => true]);
