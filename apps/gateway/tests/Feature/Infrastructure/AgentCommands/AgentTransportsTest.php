<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\LocalAgentTransport;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Models\Node;

it('keeps the recorded serving Node local while its Gateway role reconverges', function (): void {
    $serving = transportNode('serving');
    $serving->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Failed, 'failed_step' => 'converge:gateway-web']);
    $candidate = transportNode('candidate', '10.44.0.4');
    $candidate->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($serving);

    expect(app(AgentTransports::class)->for($serving))->toBeInstanceOf(LocalAgentTransport::class)
        ->and(app(AgentTransports::class)->for($candidate))->toBeInstanceOf(SshAgentTransport::class);
});

it('uses local commands during the Gateway bootstrap before its serving record exists', function (): void {
    $node = transportNode('bootstrap');
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Provisioning]);

    expect(app(AgentTransports::class)->for($node))->toBeInstanceOf(LocalAgentTransport::class);
});

function transportNode(string $name, string $address = '10.44.0.3'): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test", 'wireguard_ip' => $address, 'user' => 'orbit',
    ]);
}
