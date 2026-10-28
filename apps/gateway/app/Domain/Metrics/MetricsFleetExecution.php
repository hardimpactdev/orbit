<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Verifies the complete fleet proof before the final Gateway receipt is allowed. */
final readonly class MetricsFleetExecution implements OperationFamilyExecution, OperationFamilyChildren
{
    public function __construct(private MetricsChildIntent $children, private MetricsFleetChildren $intents) {}

    public function context(Operation $operation): array
    {
        return ['descriptors' => [['key' => 'integration-receipt', 'step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt',
            'input' => ['backend' => $operation->input['remove'] ? 'absent' : 'active'], 'resumable' => true, 'required' => true]]];
    }

    /** @return array{intent: array<string, mixed>, children: list<array{key: string, kind: string, node_id: int, input: array<string, mixed>}>} */
    public function childContext(Operation $operation): array
    {
        return ['intent' => $operation->input, 'children' => $this->intents->context($operation)];
    }

    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->prove($operation);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->prove($operation);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function prove(Operation $operation): void
    {
        if (($operation->input['preference_only'] ?? false) === true) {
            return;
        }
        $input = $operation->input;
        $healthyServices = [];
        $targets = [];
        if (! $input['unreachable']) {
            foreach (MetricsIntent::fleet($operation) as $node) {
                $addresses = ['address' => $node['address'], 'metrics_address' => $input['metrics_address']];
                $degraded = false;
                foreach (['node_exporter', 'cadvisor'] as $component) {
                    $snapshot = $this->requireChild($operation, $component.'-snapshot-'.$node['node_id'], 'metrics.'.$component.'_snapshot', $node['node_id'], $addresses, false);
                    if ($snapshot->status !== OperationStatus::Succeeded) {
                        if ($node['node_id'] === $operation->node_id || $snapshot->status !== OperationStatus::Failed || ExporterDegradationReason::fromErrorCode($snapshot->error_code ?? '') === null) {
                            $this->unproven();
                        }
                        $degraded = true;
                        break;
                    }
                    $this->requireChild($operation, $component.'-apply-'.$node['node_id'], 'metrics.'.$component.($node['selected'] ? '_converge' : '_remove'), $node['node_id'], $addresses);
                }
                if ($degraded) {
                    continue;
                }
                if ($node['selected']) {
                    $targets[] = ['name' => $node['name'], 'address' => $node['address']];
                }
                $serviceInput = [...$addresses, 'instance_ids' => $node['instance_ids'], 'caddy' => $node['caddy'], 'fpm' => $node['fpm']];
                $snapshot = $this->requireChild($operation, 'service-snapshot-'.$node['node_id'], 'metrics.service_snapshot', $node['node_id'], $serviceInput, false);
                if ($snapshot->status === OperationStatus::Failed) {
                    continue;
                }
                if ($snapshot->status !== OperationStatus::Succeeded) {
                    $this->unproven();
                }
                $apply = $this->requireChild($operation, 'service-apply-'.$node['node_id'], 'metrics.service_converge', $node['node_id'], $serviceInput, false);
                if ($apply->status === OperationStatus::Failed) {
                    $key = 'service-restore-'.$node['node_id'];
                    $this->requireChild($operation, $key, 'metrics.service_restore', $node['node_id'], [...$serviceInput, 'source_operation_id' => $snapshot->id, 'source_key' => 'service-snapshot-'.$node['node_id'], 'restore_key' => $key], false);

                    continue;
                }
                if ($apply->status !== OperationStatus::Succeeded) {
                    $this->unproven();
                }
                $this->requireChild($operation, 'service-caddy-'.$node['node_id'], 'route.caddy_converge', $node['node_id'], ['reason' => 'metrics service projection']);
                $healthyServices[] = $node;
            }
            if ($input['remove']) {
                $this->requireChild($operation, 'metrics-runtime', 'metrics.runtime_remove', MetricsIntent::nodeId($operation), ['purge_data' => $input['purge_data']]);
            } else {
                usort($targets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
                $this->requireChild($operation, 'metrics-runtime', 'metrics.runtime_converge', MetricsIntent::nodeId($operation),
                    ['assignment_id' => $input['assignment_id'], 'targets' => $targets, 'services' => $healthyServices, 'service_node_ids' => array_column($healthyServices, 'node_id')]);
                $this->requireChild($operation, 'metrics-credentials-verified', 'metrics.runtime_verify', MetricsIntent::nodeId($operation), []);
            }
        }
        $gateway = $input['gateway_node_id'] === null ? null : ValidatedData::integer($input['gateway_node_id']);
        if ($gateway !== null) {
            $this->requireChild($operation, 'publication-caddy', 'route.caddy_converge', $gateway, ['reason' => $input['remove'] ? 'metrics withdrawal' : 'metrics publication']);
            if (! $input['remove'] || ! $input['relocated']) {
                $this->requireChild($operation, 'publication-certificate', $input['remove'] ? 'metrics.certificate_remove' : 'metrics.certificate_publish', $gateway, []);
            }
            if (! $input['unreachable']) {
                $this->requireChild($operation, 'publication-firewall', 'metrics.publication_firewall', MetricsIntent::nodeId($operation),
                    ['action' => ['operation' => $input['remove'] ? 'remove' : 'converge', 'gateway' => $input['gateway_address']]]);
            }
            if ($input['dns_node_id'] !== null) {
                $this->requireChild($operation, 'publication-dns', 'route.private_dns_publish', ValidatedData::integer($input['dns_node_id']), ['reason' => $input['remove'] ? 'metrics withdrawal' : 'metrics publication']);
            }
        } elseif (! $input['remove']) {
            $this->unproven();
        }
    }

    /** @param array<string, mixed> $input */
    private function requireChild(Operation $operation, string $key, string $kind, int $nodeId, array $input, bool $success = true): Operation
    {
        $child = $this->children->find($operation, $key);
        if (! $child instanceof Operation || $child->kind !== $kind || $child->node_id !== $nodeId || $child->input !== $input || ! $child->status->terminal() || ($success && $child->status !== OperationStatus::Succeeded)) {
            $this->unproven();
        }

        return $child;
    }

    private function unproven(): never
    {
        throw new ResourceOperationException('operation.completion_unproven', 'The Metrics fleet has no complete set of matching child receipts.', 409);
    }
}
