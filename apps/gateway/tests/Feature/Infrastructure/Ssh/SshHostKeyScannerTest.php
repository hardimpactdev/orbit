<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshHostKeyScanner;
use App\Models\Node;

it('scans through the agent on the serving Gateway or the selected jump Node', function (bool $viaJump): void {
    $gateway = Node::query()->create(['name' => 'gateway-scan', 'public_ssh_host' => '192.0.2.1', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit']);
    $jump = Node::query()->create(['name' => 'jump-scan', 'public_ssh_host' => '192.0.2.2', 'platform' => 'linux', 'architecture' => 'aarch64', 'user' => 'nick']);
    $serving = app(GatewayServingHost::class);
    $serving->remember($gateway);
    $target = $viaJump ? $jump : $gateway;
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (Node $node, string $command, array $input) use ($target): bool {
        return $node->id === $target->id && $node->architecture === $target->architecture
            && $command === 'nodes.scan-host-key' && $input === ['host' => '192.0.2.10', 'port' => 2222];
    })->andReturn(['type' => 'ssh-ed25519', 'value' => 'PUBLIC', 'fingerprint' => 'SHA256:approved']);

    $key = new SshHostKeyScanner($runner, $serving)->scan('192.0.2.10', 2222, $viaJump ? $jump : null);

    expect([$key->type, $key->value, $key->fingerprint])->toBe(['ssh-ed25519', 'PUBLIC', 'SHA256:approved']);
})->with(['serving Gateway' => false, 'jump Node' => true]);

it('keeps the typed refusal or transport diagnostics when the scan fails', function (bool $transport): void {
    $node = new Node(['name' => 'jump', 'platform' => 'linux']);
    $result = new CommandResult(255, '', 'transport failed', 25000, true);
    $failure = $transport
        ? new AgentRunnerFailure('nodes.scan-host-key', AgentRunnerFailure::Timeout, 'Timed out.', result: $result)
        : new AgentCommandRefused('nodes.scan-host-key', 'nodes.host_key_scan_failed', 'Scan refused.');
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andThrow($failure);

    expect(fn () => new SshHostKeyScanner($runner, app(GatewayServingHost::class))->scan('192.0.2.10', 22, $node))
        ->toThrow(function (SshHostKeyScanException $exception) use ($failure, $transport, $result): void {
            expect($exception->getPrevious())->toBe($failure)
                ->and($exception->result)->toBe($transport ? $result : null);
        });
})->with(['refusal' => false, 'transport' => true]);

it('refuses a direct scan when the serving Gateway is not registered', function (): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');

    expect(fn () => new SshHostKeyScanner($runner, app(GatewayServingHost::class))->scan('192.0.2.10', 22))
        ->toThrow(SshHostKeyScanException::class, 'serving Gateway');
});

it('refuses a malformed agent host key result', function (): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['type' => 'ssh-ed25519', 'value' => ['invalid'], 'fingerprint' => 'SHA256:approved']);

    expect(fn () => new SshHostKeyScanner($runner, app(GatewayServingHost::class))->scan('192.0.2.10', 22, new Node))
        ->toThrow(SshHostKeyScanException::class, 'invalid host key');
});
