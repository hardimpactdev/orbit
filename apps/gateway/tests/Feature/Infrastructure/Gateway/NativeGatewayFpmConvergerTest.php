<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('sends rendered pool and configured contributions and returns the native activation decision', function (bool $activated): void {
    config(['orbit.gateway_checkout' => '/home/orbit/gateway', 'orbit.home' => '/home/orbit/.orbit']);
    $agents = new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => true, 'activated' => $activated]);
    $converger = new NativeGatewayFpmConverger($agents, [...app()->tagged(GatewayServiceStartHook::class)]);

    expect($converger->converge(new Node(['name' => 'gateway']), "[orbit-gateway]\n"))->toBe($activated)
        ->and($agents->calls[0]['input'])->toBe(['pool' => "[orbit-gateway]\n", 'hooks' => [
            ['command' => 'documents.prepare-cleanup', 'input' => ['checkout' => '/home/orbit/gateway', 'orbit_home' => '/home/orbit/.orbit']],
        ]]);
})->with([true, false]);

it('maps native validation activation and recovery refusals', function (string $cause, array $details, string $code): void {
    $agents = new FakeAgentCommandRunner()->refuse('gateway.converge-fpm', $cause, $details);
    $converger = new NativeGatewayFpmConverger($agents, []);

    expect(fn () => $converger->converge(new Node, 'pool'))->toThrow(fn (NodeProvisioningException $error) => expect($error->errorCode)->toBe($code));
})->with([
    ['gateway.fpm_invalid', [], 'gateway.fpm_config_invalid'],
    ['gateway.fpm_failed', ['stage' => 'validate'], 'gateway.fpm_config_invalid'],
    ['gateway.fpm_unsafe', [], 'gateway.fpm_config_install_failed'],
    ['gateway.fpm_failed', ['stage' => 'reload-or-restart'], 'gateway.fpm_start_failed'],
    ['gateway.fpm_hook_failed', [], 'gateway.fpm_start_failed'],
    ['gateway.fpm_recovery_failed', [], 'gateway.fpm_recovery_failed'],
]);

it('rejects a missing activation result and malformed connection observations', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('gateway.converge-fpm', [])->answer('gateway.inspect-fpm-connections', ['connections' => -1]);
    $converger = new NativeGatewayFpmConverger($agents, []);

    expect(fn () => $converger->converge(new Node, 'pool'))->toThrow(NodeProvisioningException::class)
        ->and(fn () => $converger->connections(new Node))->toThrow(AgentRunnerFailure::class);
});

it('reads connection counts through the native observer', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('gateway.inspect-fpm-connections', ['connections' => 3]);
    expect(new NativeGatewayFpmConverger($agents, [])->connections(new Node(['name' => 'gateway'])))->toBe(3)
        ->and($agents->calls[0]['input'])->toBe([]);
});

arch('Gateway PHP-FPM uses only the typed agent boundary')
    ->expect(NativeGatewayFpmConverger::class)
    ->not->toUse([ProcessRunner::class, SshExecutor::class, RemoteProgramCatalog::class]);
