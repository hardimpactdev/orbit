<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;

/** Maps the agent's exporter results onto the fleet lifecycle contract. */
final readonly class MetricsExporterCommands
{
    public function __construct(private AgentCommandRunner $agents, private CommandDeadline $deadline) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function run(Node $node, Node $metricsNode, string $command, array $action): array
    {
        $prefix = $command === 'metrics.node-exporter' ? 'metrics.exporter_' : 'metrics.cadvisor_';
        foreach ([$node, $metricsNode] as $target) {
            if (! is_string($target->wireguard_ip) || filter_var($target->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException($prefix.'address_invalid', 'The exporter requires valid WireGuard IPv4 addresses.', 409);
            }
        }
        try {
            $result = $this->agents->run($node, $command, [
                'address' => $node->wireguard_ip,
                'metrics_address' => $metricsNode->wireguard_ip,
                'action' => $action,
            ], $this->deadline->cap(420.0));
        } catch (AgentCommandRefused $exception) {
            $status = $exception->details['status'] ?? 502;
            throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), in_array($status, [409, 422, 502], true) ? $status : 502, $exception);
        } catch (AgentRunnerFailure $exception) {
            // An unavailable snapshot can degrade this fleet member, as the former SSH inspection did.
            throw new ResourceOperationException($prefix.'configuration_inspection_failed', 'The exporter command did not return a valid result.', 502, $exception);
        }
        $valid = match ($action['operation']) {
            'snapshot' => array_key_exists('configuration', $result)
                && ($result['configuration'] === null || is_string($result['configuration']))
                && is_bool($result['service_active'] ?? null)
                && array_key_exists('firewall_source', $result)
                && ($result['firewall_source'] === null || (is_string($result['firewall_source']) && filter_var($result['firewall_source'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)),
            'actual' => in_array($result['actual'] ?? null, ['active', 'inactive', 'drift', 'unknown'], true),
            default => is_bool($result['changed'] ?? null),
        };
        if (! $valid) {
            throw new ResourceOperationException($prefix.'configuration_inspection_failed', 'The exporter command returned an invalid result.', 502);
        }

        return $result;
    }

    public function snapshot(Node $node, Node $metricsNode, string $command): MetricsExporterState
    {
        $data = $this->run($node, $metricsNode, $command, ['operation' => 'snapshot']);

        return new MetricsExporterState(
            configuration: is_string($data['configuration']) ? $data['configuration'] : null,
            serviceActive: $data['service_active'] === true,
            firewallSource: is_string($data['firewall_source']) ? $data['firewall_source'] : null,
        );
    }
}
