<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\GatewayServiceCommands;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = (new FakeAgentCommandRunner)
        ->answer('gateway.converge-fleet-units', ['changed' => true])
        ->answer('gateway.converge-agent-view', ['changed' => true])
        ->answer('gateway.converge-hibernator', ['changed' => false])
        ->answer('gateway.start-fleet-run', ['accepted' => true]);
    app()->instance(AgentCommandRunner::class, $this->agents);
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit', 'orbit.hibernation.sweep_seconds' => 73]);
});

it('wires the service managers through native commands with Gateway intent', function (): void {
    app(AgentViewConverger::class)->converge();
    app(RuntimeHibernatorConverger::class)->converge();
    $identity = ['application' => base_path(), 'orbit_home' => '/home/orbit/.orbit', 'user' => 'orbit'];
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.converge-agent-view', 'input' => $identity],
        ['node' => 'gateway', 'command' => 'gateway.converge-hibernator', 'input' => [...$identity, 'sweep_seconds' => 73]],
    ]);
});

it('preserves the family error and native failure stage', function (string $method, string $command, string $code, string $stage): void {
    $this->agents->refuse($command, $code, ['stage' => $stage]);
    $commands = app(GatewayServiceCommands::class);
    expect(fn () => $method === 'hibernator' ? $commands->hibernator(73) : $commands->$method())
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->step)->toBe($stage)->and($e->errorCode)->toBe($code));
})->with([
    ['agentView', 'gateway.converge-agent-view', 'gateway.agent_view_install_failed', 'gateway-agent-view-enable'],
    ['hibernator', 'gateway.converge-hibernator', 'gateway.hibernator_install_failed', 'gateway-hibernator-reload'],
]);

it('keeps malformed and lost results as convergence failures', function (bool $lost): void {
    $this->agents->answerMalformed('gateway.converge-agent-view', $lost
        ? static fn (): array => throw new AgentRunnerFailure('gateway.converge-agent-view', AgentRunnerFailure::Timeout, 'expired')
        : ['changed' => 'yes']);
    expect(fn () => app(AgentViewConverger::class)->converge())
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->errorCode)->toBe('gateway.agent_view_install_failed'));
})->with([false, true]);

it('does not send a service command without an active Gateway Node', function (): void {
    $this->node->roles()->delete();
    expect(fn () => app(AgentViewConverger::class)->converge())->toThrow(NodeProvisioningException::class);
    expect($this->agents->calls)->toBe([]);
});

it('installs service units during bootstrap but does not start rollout work before activation', function (): void {
    $this->node->update(['status' => LifecycleStatus::Provisioning]);
    $this->node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    app(AgentViewConverger::class)->converge();
    app(RuntimeHibernatorConverger::class)->converge();
    expect($this->agents->commands())->toBe(['gateway.converge-agent-view', 'gateway.converge-hibernator']);
});

it('refuses unit changes for a failed or removing Gateway', function (LifecycleStatus $status): void {
    $this->node->update(['status' => $status]);
    $this->node->roles()->update(['status' => $status]);
    expect(fn () => app(AgentViewConverger::class)->converge())->toThrow(NodeProvisioningException::class);
    expect($this->agents->calls)->toBe([]);
})->with([LifecycleStatus::Failed, LifecycleStatus::Removing]);
