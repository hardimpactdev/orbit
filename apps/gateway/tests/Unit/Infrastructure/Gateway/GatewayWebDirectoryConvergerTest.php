<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('requests the configured web directory on the Gateway Node', function (): void {
    $agents = new FakeAgentCommandRunner(new AgentContract(dirname(__DIR__, 5).'/agent/contract/commands.json'))->answer('gateway.prepare-web-directory', ['changed' => false]);
    $node = new Node(['name' => 'gateway']);
    $converger = new GatewayWebDirectoryConverger($agents, '/home/orbit/web');

    $converger->converge($node);
    $converger->converge($node);

    expect($agents->calls)->toBe(array_fill(0, 2, [
        'node' => 'gateway', 'command' => 'gateway.prepare-web-directory', 'input' => ['path' => '/home/orbit/web'],
    ]));
});

it('maps native directory refusals to the provisioning step', function (string $code, string $step): void {
    $agents = new FakeAgentCommandRunner(new AgentContract(dirname(__DIR__, 5).'/agent/contract/commands.json'))->refuse('gateway.prepare-web-directory', $code);

    expect(fn () => new GatewayWebDirectoryConverger($agents, '/home/orbit/web')->converge(new Node(['name' => 'gateway'])))
        ->toThrow(function (NodeProvisioningException $error) use ($code, $step): void {
            expect($error->errorCode)->toBe($code)->and($error->step)->toBe($step);
        });
    expect($agents->commands())->toBe(['gateway.prepare-web-directory']);
})->with([
    ['gateway.web_directory_invalid', 'gateway-web-validate'],
    ['gateway.web_directory_failed', 'gateway-web-directory'],
]);

it('keeps a transport failure attached to the failed provisioning step', function (): void {
    $failure = new AgentRunnerFailure('gateway.prepare-web-directory', AgentRunnerFailure::Transport, 'Connection lost.');
    $agents = new FakeAgentCommandRunner(new AgentContract(dirname(__DIR__, 5).'/agent/contract/commands.json'))->answer('gateway.prepare-web-directory', static fn (): never => throw $failure);

    expect(fn () => new GatewayWebDirectoryConverger($agents, '/home/orbit/web')->converge(new Node(['name' => 'gateway'])))
        ->toThrow(function (NodeProvisioningException $error) use ($failure): void {
            expect($error->errorCode)->toBe('gateway.web_directory_failed')->and($error->getPrevious())->toBe($failure);
        });
    expect($agents->commands())->toBe(['gateway.prepare-web-directory']);
});

arch('Gateway web directory preparation uses the agent boundary')
    ->expect(GatewayWebDirectoryConverger::class)->not->toUse([ProcessRunner::class, SshExecutor::class]);
