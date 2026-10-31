<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

/**
 * Derives exact keyed child intents from the admitted fleet and proven sibling receipts.
 *
 * @phpstan-import-type Candidate from MetricsIntent
 */
final class MetricsFleetChildren
{
    /** @var array<string, array{key: string, kind: string, node_id: int, input: array<string, mixed>}> */
    private array $intents = [];

    public function __construct(private readonly MetricsChildIntent $children) {}

    /** @return list<array{key: string, kind: string, node_id: int, input: array<string, mixed>}> */
    public function context(Operation $operation): array
    {
        $this->intents = [];
        $input = $operation->input;
        $fleet = MetricsIntent::fleet($operation);
        $degraded = [];
        $healthyServices = [];
        $ready = true;
        $fatal = false;
        if (! $input['unreachable']) {
            foreach (['node_exporter', 'cadvisor'] as $component) {
                $snapshots = [];
                foreach ($fleet as $node) {
                    if (in_array($node['node_id'], $degraded, true)) {
                        continue;
                    }
                    $snapshot = $this->add($operation, MetricsIntent::snapshotKey($operation, $component, $node['node_id']), 'metrics.'.$component.'_snapshot', $node['node_id'], $this->addresses($operation, $node));
                    if ($snapshot === null || ! $snapshot->status->terminal()) {
                        $ready = false;

                        continue;
                    }
                    if ($snapshot->status === OperationStatus::Succeeded) {
                        $snapshots[] = $node;

                        continue;
                    }
                    if ($node['node_id'] !== $operation->node_id && $snapshot->status === OperationStatus::Failed && ExporterDegradationReason::fromErrorCode($snapshot->error_code ?? '') !== null) {
                        $degraded[] = $node['node_id'];
                    } else {
                        $ready = false;
                        $fatal = true;
                    }
                }
                if (! $ready) {
                    break;
                }
                foreach ($snapshots as $node) {
                    $apply = $this->add($operation, $component.'-apply-'.$node['node_id'], 'metrics.'.$component.($node['selected'] ? '_converge' : '_remove'), $node['node_id'], $this->addresses($operation, $node));
                    if ($apply?->status !== OperationStatus::Succeeded) {
                        $ready = false;
                        $fatal = $apply?->status->terminal() ?? false;
                        break;
                    }
                }
                if (! $ready) {
                    break;
                }
            }
            if ($ready) {
                $snapshots = [];
                foreach ($fleet as $node) {
                    if (in_array($node['node_id'], $degraded, true)) {
                        continue;
                    }
                    $snapshot = $this->add($operation, 'service-snapshot-'.$node['node_id'], 'metrics.service_snapshot', $node['node_id'], $this->service($operation, $node));
                    if ($snapshot === null || ! $snapshot->status->terminal()) {
                        $ready = false;
                    } elseif ($snapshot->status === OperationStatus::Succeeded) {
                        $snapshots[] = $node;
                    } elseif (MetricsChildIntent::interrupted($snapshot)) {
                        $ready = false;
                        $fatal = true;
                    }
                }
                if ($ready) {
                    foreach ($snapshots as $node) {
                        $apply = $this->add($operation, 'service-apply-'.$node['node_id'], 'metrics.service_converge', $node['node_id'], $this->service($operation, $node));
                        if ($apply === null || ! $apply->status->terminal()) {
                            $ready = false;
                            break;
                        }
                        if ($apply->status !== OperationStatus::Succeeded) {
                            if (MetricsChildIntent::interrupted($apply)) {
                                $ready = false;
                                $fatal = true;
                                break;
                            }
                            $restored = $this->restore($operation, $node, 'service');
                            foreach (['service-restore-', 'service-caddy-restore-'] as $prefix) {
                                $recovery = $this->children->find($operation, $prefix.$node['node_id']);
                                if ($recovery !== null && MetricsChildIntent::interrupted($recovery)) {
                                    $fatal = true;
                                }
                            }
                            if (! $restored || $fatal) {
                                $ready = false;
                                break;
                            }

                            continue;
                        }
                        if ($node['caddy']) {
                            $caddy = $this->add($operation, 'service-caddy-'.$node['node_id'], 'route.caddy_converge', $node['node_id'], ['reason' => 'metrics service projection']);
                            if ($caddy?->status !== OperationStatus::Succeeded) {
                                $ready = false;
                                $fatal = $caddy?->status->terminal() ?? false;
                                break;
                            }
                        }
                        $healthyServices[] = $node;
                    }
                }
            }
            if ($ready) {
                $targets = [];
                foreach ($fleet as $node) {
                    if ($node['selected'] && ! in_array($node['node_id'], $degraded, true)) {
                        $targets[] = ['name' => $node['name'], 'address' => $node['address']];
                    }
                }
                usort($targets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
                $runtime = $this->add($operation, 'metrics-runtime', 'metrics.runtime_converge', MetricsIntent::nodeId($operation), ['assignment_id' => $input['assignment_id'],
                    'targets' => $targets, 'services' => $healthyServices, 'service_node_ids' => array_column($healthyServices, 'node_id')]);
                $ready = $runtime?->status === OperationStatus::Succeeded;
                if (! $ready) {
                    $fatal = $runtime?->status->terminal() ?? false;
                }
                if ($ready) {
                    $ready = $this->add($operation, 'metrics-credentials-verified', 'metrics.runtime_verify', MetricsIntent::nodeId($operation), [])?->status === OperationStatus::Succeeded;
                }
            }
        }
        $committed = $this->children->find($operation, 'metrics-runtime')?->status === OperationStatus::Succeeded;
        if (! $committed && ($fatal || $operation->cancel_requested_at !== null)) {
            foreach (array_reverse($fleet) as $node) {
                foreach (['service', 'cadvisor', 'node_exporter'] as $component) {
                    if ($this->children->find($operation, $component.'-apply-'.$node['node_id'])?->status->terminal()) {
                        $this->restore($operation, $node, $component);
                    }
                }
            }
        }

        $intents = $this->orderedIntents();

        return $operation->cancel_requested_at === null ? $intents : array_values(array_filter($intents,
            static fn (array $intent): bool => str_contains($intent['key'], '-restore-')));
    }

    /** @return list<array{key: string, kind: string, node_id: int, input: array<string, mixed>}> */
    private function orderedIntents(): array
    {
        return array_values($this->intents);
    }

    /** @param array<string, mixed> $input */
    private function add(Operation $parent, string $key, string $kind, int $nodeId, array $input): ?Operation
    {
        $this->intents[$key] = ['key' => $key, 'kind' => $kind, 'node_id' => $nodeId, 'input' => $input];

        return $this->children->find($parent, $key);
    }

    /** @param Candidate $node */
    private function restore(Operation $parent, array $node, string $component): bool
    {
        $sourceKey = MetricsIntent::snapshotKey($parent, $component, $node['node_id']);
        $snapshot = $this->children->find($parent, $sourceKey);
        if ($snapshot?->status !== OperationStatus::Succeeded) {
            return false;
        }
        $key = $component.'-restore-'.$node['node_id'];
        $input = $component === 'service' ? $this->service($parent, $node) : $this->addresses($parent, $node);
        $restore = $this->add($parent, $key, 'metrics.'.$component.'_restore', $node['node_id'], [...$input, 'source_operation_id' => $snapshot->id, 'source_key' => $sourceKey, 'restore_key' => $key]);
        if ($restore === null || ! $restore->status->terminal()) {
            return false;
        }
        if ($component === 'service' && $node['caddy']) {
            return $this->add($parent, 'service-caddy-restore-'.$node['node_id'], 'metrics.service_caddy_restore', $node['node_id'], ['restore_key' => $key])?->status->terminal() ?? false;
        }

        return true;
    }

    /** @param Candidate $node
     * @return array{address: string, metrics_address: mixed}
     */
    private function addresses(Operation $operation, array $node): array
    {
        return ['address' => $node['address'], 'metrics_address' => $operation->input['metrics_address']];
    }

    /** @param Candidate $node
     * @return array<string, mixed>
     */
    private function service(Operation $operation, array $node): array
    {
        return [...$this->addresses($operation, $node), 'instance_ids' => $node['instance_ids'], 'caddy' => $node['caddy'], 'fpm' => $node['fpm']];
    }
}
