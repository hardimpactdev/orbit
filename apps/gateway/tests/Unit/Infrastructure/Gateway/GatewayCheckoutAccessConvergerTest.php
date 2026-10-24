<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

function checkout_agents(): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner(new AgentContract(dirname(__DIR__, 5).'/agent/contract/commands.json'));
}
it('checks and prepares the configured checkout through named commands', function (): void {
    $agents = checkout_agents()->answer('gateway.check-checkout', ['changed' => false])->answer('gateway.prepare-checkout', ['changed' => true]);
    $node = new Node(['name' => 'gateway']);
    $converger = new GatewayCheckoutAccessConverger($agents, '/home/orbit/orbit/apps/gateway');
    $converger->validate($node);
    $converger->converge($node);
    $converger->converge($node);
    expect($agents->commands())->toBe(['gateway.check-checkout', 'gateway.prepare-checkout', 'gateway.prepare-checkout'])
        ->and(array_column($agents->calls, 'input'))->toBe(array_fill(0, 3, ['path' => '/home/orbit/orbit/apps/gateway']))
        ->and(array_column($agents->calls, 'node'))->toBe(['gateway', 'gateway', 'gateway']);
});
it('maps native refusals and retains their evidence', function (string $code, array $details, string $step, string $publicCode): void {
    $agents = checkout_agents()->refuse('gateway.prepare-checkout', $code, $details);
    expect(fn () => new GatewayCheckoutAccessConverger($agents, '/home/orbit/orbit/apps/gateway')->converge(new Node))
        ->toThrow(function (NodeProvisioningException $error) use ($step, $publicCode): void {
            expect($error->step)->toBe($step)->and($error->errorCode)->toBe($publicCode)->and($error->getPrevious())->toBeInstanceOf(AgentCommandRefused::class);
        });
    expect($agents->commands())->toBe(['gateway.prepare-checkout']);
})->with([
    ['gateway.checkout_invalid', [], 'gateway-checkout-validate', 'gateway.checkout_invalid'],
    ['gateway.checkout_failed', ['stage' => 'access'], 'gateway-checkout-access', 'gateway.checkout_access_failed'],
    ['gateway.checkout_failed', ['stage' => 'environment'], 'gateway-environment-protect', 'gateway.environment_protection_failed'],
    ['gateway.checkout_recovery_failed', [], 'gateway-checkout-access', 'gateway.checkout_access_failed'],
]);
it('keeps transport failure attached without repeating permission changes', function (): void {
    $failure = new AgentRunnerFailure('gateway.prepare-checkout', AgentRunnerFailure::Transport, 'Lost connection.');
    $agents = checkout_agents()->answer('gateway.prepare-checkout', static fn (): never => throw $failure);
    expect(fn () => new GatewayCheckoutAccessConverger($agents, '/home/orbit/orbit/apps/gateway')->converge(new Node))
        ->toThrow(function (NodeProvisioningException $error) use ($failure): void {
            expect($error->getPrevious())->toBe($failure)->and($error->errorCode)->toBe('gateway.checkout_access_failed');
        });
    expect($agents->commands())->toBe(['gateway.prepare-checkout']);
});
arch('Gateway checkout access has one agent execution boundary')->expect(GatewayCheckoutAccessConverger::class)->not->toUse([ProcessRunner::class, SshExecutor::class]);
