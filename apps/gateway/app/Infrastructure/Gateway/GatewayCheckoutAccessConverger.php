<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;

final readonly class GatewayCheckoutAccessConverger
{
    public function __construct(private AgentCommandRunner $agents, private string $checkoutPath) {}

    public function validate(Node $node): void
    {
        $this->run($node, 'gateway.check-checkout');
    }

    public function converge(Node $node): void
    {
        $this->run($node, 'gateway.prepare-checkout');
    }

    private function run(Node $node, string $command): void
    {
        try {
            $this->agents->run($node, $command, ['path' => $this->checkoutPath]);
        } catch (AgentCommandRefused $refused) {
            [$step, $code] = match (true) {
                $refused->errorCode === 'gateway.checkout_invalid' => ['gateway-checkout-validate', 'gateway.checkout_invalid'],
                ($refused->details['stage'] ?? null) === 'environment' => ['gateway-environment-protect', 'gateway.environment_protection_failed'],
                default => ['gateway-checkout-access', 'gateway.checkout_access_failed'],
            };
            throw new NodeProvisioningException($step, $code, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('gateway-checkout-access', 'gateway.checkout_access_failed', 'Gateway checkout convergence did not return a result.', previous: $failure, result: $failure->result);
        }
    }
}
