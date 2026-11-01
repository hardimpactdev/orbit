<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

function gatewayWebNode(LifecycleStatus $roleStatus = LifecycleStatus::Active): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => $roleStatus]);

    return $node;
}

it('refuses manual Gateway web convergence before any effect or state change', function (LifecycleStatus $roleStatus): void {
    $node = gatewayWebNode($roleStatus);
    $role = $node->roles()->sole();
    $before = $node->fresh()->toArray();
    $roleBefore = $role->fresh()->toArray();
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $certificates = Mockery::mock(GatewayCertificateIssuer::class);
    $certificates->shouldNotReceive('issue');
    app()->instance(GatewayCertificateIssuer::class, $certificates);

    $this->artisan('orbit:gateway-web')
        ->expectsOutput('Gateway web convergence failed at step [gateway-web] with error [gateway.web_unavailable].')
        ->assertExitCode(1);

    expect($agents->calls)->toBe([])
        ->and($node->fresh()->toArray())->toBe($before)
        ->and($role->fresh()->toArray())->toBe($roleBefore);
})->with([LifecycleStatus::Active, LifecycleStatus::Provisioning]);
