<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;
use Illuminate\Support\Facades\Config;

/** Control-plane service intent; native commands own unit files and systemd calls. */
final readonly class GatewayServiceCommands
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function agentView(): void
    {
        $this->converge('gateway.converge-agent-view', 'gateway-agent-view-service', 'gateway.agent_view_install_failed');
    }

    public function hibernator(int $sweepSeconds): void
    {
        $this->converge('gateway.converge-hibernator', 'gateway-hibernator-service', 'gateway.hibernator_install_failed', ['sweep_seconds' => $sweepSeconds]);
    }

    /** @param array<string, mixed> $policy */
    private function converge(string $command, string $step, string $failure, array $policy = []): void
    {
        $result = $this->run($command, [
            'application' => GatewayApplicationPath::resolve(),
            'orbit_home' => Config::string('orbit.home'),
            'user' => 'orbit',
            ...$policy,
        ], $step, $failure);
        if (! is_bool($result['changed'] ?? null)) {
            throw new NodeProvisioningException($step, $failure, 'The agent returned no Gateway service convergence result.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run(string $command, array $input, string $step, string $failure): array
    {
        $node = GatewayCommandNode::find(converging: $command !== 'gateway.start-fleet-run');
        if (! $node instanceof Node) {
            throw new NodeProvisioningException($step, $failure, 'No Gateway Node in the required state exists for service commands.');
        }
        try {
            return $this->agents->run($node, $command, $input);
        } catch (AgentCommandRefused $error) {
            $stage = $error->details['stage'] ?? null;
            throw new NodeProvisioningException(is_string($stage) ? $stage : $step, $failure, $error->getMessage(), previous: $error);
        } catch (AgentRunnerFailure $error) {
            throw new NodeProvisioningException($step, $failure, 'The agent returned no Gateway service result.', previous: $error);
        }
    }
}
