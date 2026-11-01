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
     * @return array<string, mixed>
     */
    public function run(Node $node, Node $metricsNode): array
    {
        $prefix = 'metrics.exporter_';
        foreach ([$node, $metricsNode] as $target) {
            if (! is_string($target->wireguard_ip) || filter_var($target->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException($prefix.'address_invalid', 'The exporter requires valid WireGuard IPv4 addresses.', 409);
            }
        }
        try {
            $result = $this->agents->run($node, 'metrics.node-exporter', [
                'address' => $node->wireguard_ip,
                'metrics_address' => $metricsNode->wireguard_ip,
                'action' => ['operation' => 'actual'],
            ], $this->deadline->cap(420.0));
        } catch (AgentCommandRefused $exception) {
            $status = $exception->details['status'] ?? 502;
            throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), in_array($status, [409, 422, 502], true) ? $status : 502, $exception);
        } catch (AgentRunnerFailure $exception) {
            throw new ResourceOperationException($prefix.'configuration_inspection_failed', 'The exporter command did not return a valid result.', 502, $exception);
        }
        $valid = in_array($result['actual'] ?? null, ['active', 'inactive', 'drift', 'unknown'], true);
        if (! $valid) {
            throw new ResourceOperationException($prefix.'configuration_inspection_failed', 'The exporter command returned an invalid result.', 502);
        }

        return $result;
    }
}
