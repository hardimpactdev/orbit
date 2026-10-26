<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;

final readonly class MetricsPublicationFirewall
{
    public function __construct(private AgentCommandRunner $agents, private CommandDeadline $deadline) {}

    public function converge(Node $metricsNode, string $gatewayAddress): bool
    {
        return $this->run($metricsNode, ['operation' => 'converge', 'gateway' => $gatewayAddress]);
    }

    public function remove(Node $metricsNode, string $gatewayAddress): void
    {
        $this->run($metricsNode, ['operation' => 'remove', 'gateway' => $gatewayAddress]);
    }

    public function abandon(Node $metricsNode): void
    {
        $this->run($metricsNode, ['operation' => 'abandon']);
    }

    /** @param array<string, string> $action */
    private function run(Node $node, array $action): bool
    {
        foreach ([$node->wireguard_ip, ...isset($action['gateway']) ? [$action['gateway']] : []] as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.publication_address_invalid', 'Metrics publication requires valid WireGuard IPv4 addresses.', 409);
            }
        }
        try {
            $result = $this->agents->run($node, 'metrics.publication-firewall', ['address' => $node->wireguard_ip, 'action' => $action], $this->deadline->cap(PHP_FLOAT_MAX));
        } catch (AgentCommandRefused $exception) {
            $status = match ($exception->errorCode) {
                'metrics.publication_firewall_inactive', 'metrics.publication_firewall_ownership_drift' => 409,
                'metrics.remote_command_timed_out' => 504,
                default => 502,
            };
            throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), $status, $exception);
        }
        if (! is_bool($result['changed'] ?? null)) {
            throw new ResourceOperationException('metrics.publication_firewall_inspection_failed', 'The Metrics firewall command returned an invalid result.', 502);
        }

        return $result['changed'];
    }
}
