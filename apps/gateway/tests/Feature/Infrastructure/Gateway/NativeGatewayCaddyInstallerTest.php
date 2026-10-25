<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\Gateway\NativeGatewayCaddyInstaller;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

describe('Gateway Caddy installer', function (): void {
    it('requests the pinned package and then WireGuard ordering on the Gateway Node', function (): void {
        $node = new Node(['name' => 'gateway']);
        $agents = new FakeAgentCommandRunner()->answer('caddy.install-package', ['changed' => false])->answer('caddy.order-after-vpn', ['changed' => false]);

        new NativeGatewayCaddyInstaller($agents)->install($node);

        expect($agents->calls)->toBe([
            ['node' => 'gateway', 'command' => 'caddy.install-package', 'input' => AgentCaddyPackage::input()],
            ['node' => 'gateway', 'command' => 'caddy.order-after-vpn', 'input' => []],
        ]);
    });

    it('maps each refusal and stops at the failed step', function (string $command, string $refusal, string $step, string $errorCode, int $calls): void {
        $agents = new FakeAgentCommandRunner()->answer('caddy.install-package', ['changed' => false])->refuse($command, $refusal);
        $node = new Node;

        expect(fn () => new NativeGatewayCaddyInstaller($agents)->install($node))
            ->toThrow(function (NodeProvisioningException $exception) use ($step, $errorCode): void {
                expect($exception->step)->toBe($step)
                    ->and($exception->errorCode)->toBe($errorCode)
                    ->and($exception->result?->exitCode)->toBe(1);
            });
        expect($agents->calls)->toHaveCount($calls);
    })->with([
        'package' => ['caddy.install-package', 'caddy.package_failed', 'gateway-caddy-install', 'gateway.caddy_install_failed', 1],
        'ordering' => ['caddy.order-after-vpn', 'services.ordering_failed', 'gateway-caddy-ordering', 'gateway.caddy_start_failed', 2],
    ]);
});

arch('Caddy installation and ordering use only typed agent commands')
    ->expect([
        NativeGatewayCaddyInstaller::class,
        RemoteAppDevCaddyManager::class,
        RemoteAppProdCaddyManager::class,
    ])->not->toUse([
        ProcessRunner::class,
        SshExecutor::class,
        RemoteCommand::class,
        DevelopmentSshExecutor::class,
    ]);

it('maps transport failure at the Caddy ordering step', function (): void {
    $failure = new AgentRunnerFailure('caddy.order-after-vpn', AgentRunnerFailure::Transport, 'Connection lost.');
    $agents = new FakeAgentCommandRunner()->answer('caddy.install-package', ['changed' => false])
        ->answer('caddy.order-after-vpn', static fn (): never => throw $failure);

    expect(fn () => new NativeGatewayCaddyInstaller($agents)->install(new Node))
        ->toThrow(function (NodeProvisioningException $exception) use ($failure): void {
            expect($exception->errorCode)->toBe('gateway.caddy_start_failed')
                ->and($exception->getPrevious())->toBe($failure);
        });
});
