<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Verifies the complete fleet proof before the final Gateway receipt is allowed. */
final readonly class MetricsFleetExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private MetricsChildIntent $children, private MetricsFleetChildren $intents) {}

    public function context(Operation $operation): array
    {
        return ['descriptors' => [['key' => 'integration-receipt', 'step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt',
            'input' => ['backend' => 'active'], 'resumable' => true, 'required' => true]]];
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
        } else {
            $this->proveRestoration($operation, $outcome);
        }
    }

    private function proveRestoration(Operation $operation, OperationOutcome $outcome): void
    {
        $runtime = $this->children->find($operation, 'metrics-runtime');
        if ($runtime?->status === OperationStatus::Succeeded) {
            if ($runtime->kind !== 'metrics.runtime_converge' || $runtime->node_id !== MetricsIntent::nodeId($operation)) {
                $this->unproven();
            }

            return;
        }
        $rollbackFailed = $outcome->status === OperationStatus::Failed && $outcome->errorCode === 'metrics.rollback_failed';
        foreach (['service', 'cadvisor', 'node_exporter'] as $component) {
            foreach (array_reverse(MetricsIntent::fleet($operation)) as $node) {
                $addresses = ['address' => $node['address'], 'metrics_address' => $operation->input['metrics_address']];
                $applyKey = $component.'-apply-'.$node['node_id'];
                if ($this->children->find($operation, $applyKey) === null) {
                    continue;
                }
                $input = $component === 'service' ? [...$addresses, 'instance_ids' => $node['instance_ids'], 'caddy' => $node['caddy'], 'fpm' => $node['fpm']] : $addresses;
                $applyKind = 'metrics.'.$component.($component === 'service' || $node['selected'] ? '_converge' : '_remove');
                $this->requireChild($operation, $applyKey, $applyKind, $node['node_id'], $input, false);
                $snapshotKey = MetricsIntent::snapshotKey($operation, $component, $node['node_id']);
                $snapshot = $this->requireChild($operation, $snapshotKey, 'metrics.'.$component.'_snapshot', $node['node_id'], $input);
                $key = $component.'-restore-'.$node['node_id'];
                $restore = $this->requireChild($operation, $key, 'metrics.'.$component.'_restore', $node['node_id'],
                    [...$input, 'source_operation_id' => $snapshot->id, 'source_key' => $snapshotKey, 'restore_key' => $key], false);
                if ($restore->status !== OperationStatus::Succeeded) {
                    if ($rollbackFailed && ($restore->status === OperationStatus::Cancelled || ($restore->status === OperationStatus::Failed && $restore->error_code !== null))) {
                        return;
                    }
                    $this->unproven();
                }
                if ($component === 'service') {
                    $caddy = $this->requireChild($operation, 'service-caddy-restore-'.$node['node_id'], 'metrics.service_caddy_restore', $node['node_id'], ['restore_key' => $key], false);
                    if ($caddy->status !== OperationStatus::Succeeded) {
                        if ($rollbackFailed && ($caddy->status === OperationStatus::Cancelled || ($caddy->status === OperationStatus::Failed && $caddy->error_code !== null))) {
                            return;
                        }
                        $this->unproven();
                    }
                }
            }
        }
        if ($rollbackFailed) {
            $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function prove(Operation $operation): void
    {
        $input = $operation->input;
        $healthyServices = [];
        $targets = [];
        if (! $input['unreachable']) {
            foreach (MetricsIntent::fleet($operation) as $node) {
                $addresses = ['address' => $node['address'], 'metrics_address' => $input['metrics_address']];
                $degraded = false;
                foreach (['node_exporter', 'cadvisor'] as $component) {
                    $snapshot = $this->requireChild($operation, MetricsIntent::snapshotKey($operation, $component, $node['node_id']), 'metrics.'.$component.'_snapshot', $node['node_id'], $addresses, false);
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
                if (MetricsChildIntent::interrupted($snapshot)) {
                    $this->unproven();
                }
                if ($snapshot->status === OperationStatus::Failed) {
                    continue;
                }
                if ($snapshot->status !== OperationStatus::Succeeded) {
                    $this->unproven();
                }
                $apply = $this->requireChild($operation, 'service-apply-'.$node['node_id'], 'metrics.service_converge', $node['node_id'], $serviceInput, false);
                if (MetricsChildIntent::interrupted($apply)) {
                    $this->unproven();
                }
                if ($apply->status === OperationStatus::Failed) {
                    $key = 'service-restore-'.$node['node_id'];
                    $restore = $this->requireChild($operation, $key, 'metrics.service_restore', $node['node_id'], [...$serviceInput, 'source_operation_id' => $snapshot->id, 'source_key' => 'service-snapshot-'.$node['node_id'], 'restore_key' => $key], false);
                    $caddy = $this->requireChild($operation, 'service-caddy-restore-'.$node['node_id'], 'metrics.service_caddy_restore', $node['node_id'], ['restore_key' => $key], false);

                    if (MetricsChildIntent::interrupted($restore) || MetricsChildIntent::interrupted($caddy)) {
                        $this->unproven();
                    }

                    continue;
                }
                if ($apply->status !== OperationStatus::Succeeded) {
                    $this->unproven();
                }
                $this->requireChild($operation, 'service-caddy-'.$node['node_id'], 'route.caddy_converge', $node['node_id'], ['reason' => 'metrics service projection']);
                $healthyServices[] = $node;
            }
            usort($targets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $this->requireChild($operation, 'metrics-runtime', 'metrics.runtime_converge', MetricsIntent::nodeId($operation),
                ['assignment_id' => $input['assignment_id'], 'targets' => $targets, 'services' => $healthyServices, 'service_node_ids' => array_column($healthyServices, 'node_id')]);
            $this->requireChild($operation, 'metrics-credentials-verified', 'metrics.runtime_verify', MetricsIntent::nodeId($operation), []);
        }
    }

    /** @param array<string, mixed> $input */
    private function requireChild(Operation $operation, string $key, string $kind, int $nodeId, array $input, bool $success = true): Operation
    {
        $child = $this->children->find($operation, $key);
        if (! $child instanceof Operation || $child->kind !== $kind || $child->node_id !== $nodeId || (ValidatedData::object($child->child_admission['intent'] ?? [])['input'] ?? null) !== $input || ! $child->status->terminal() || ($success && $child->status !== OperationStatus::Succeeded)) {
            $this->unproven();
        }

        return $child;
    }

    private function unproven(): never
    {
        throw new ResourceOperationException('operation.completion_unproven', 'The Metrics fleet has no complete set of matching child receipts.', 409);
    }
}
