<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;

/** Installs Caddy and its WireGuard ordering before the Gateway prepares web directories and hooks. */
final readonly class NativeGatewayCaddyInstaller
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function install(Node $node): void
    {
        $this->run($node, 'caddy.install-package', AgentCaddyPackage::input(), 'gateway-caddy-install', 'gateway.caddy_install_failed');
        $this->run($node, 'caddy.order-after-vpn', [], 'gateway-caddy-ordering', 'gateway.caddy_start_failed');
    }

    /** @param array<string, mixed> $input */
    private function run(Node $node, string $command, array $input, string $step, string $errorCode): void
    {
        try {
            $this->agents->run($node, $command, $input);
        } catch (AgentCommandRefused $refused) {
            throw new NodeProvisioningException($step, $errorCode, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException($step, $errorCode, "Gateway Caddy installation step [{$step}] failed.", previous: $failure, result: $failure->result);
        }
    }
}
