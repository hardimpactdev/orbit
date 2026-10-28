<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Fixed exporter primitives, with restore input taken only from a proven sibling snapshot. */
final readonly class MetricsLeafExecution implements OperationFamilyExecution
{
    public function __construct(private MetricsChildIntent $children) {}
    public function context(Operation $operation): array
    {
        preg_match('/\Ametrics\.(node_exporter|cadvisor)_(snapshot|restore)\z/', $operation->kind, $parts);
        if ($parts === []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Metrics snapshot effect.', 422);
        }
        $action = ['operation' => $parts[2]];
        if ($parts[2] === 'restore') {
            $source = $this->children->snapshot($operation);
            if ($source->kind !== 'metrics.'.$parts[1].'_snapshot') {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Metrics snapshot component does not match.', 422);
            }
            $result = $source->result;
            $action['state'] = ['configuration' => $result['configuration'], 'service_active' => $result['service_active'], 'firewall_source' => $result['firewall_source']];
        }
        return ['descriptors' => [['key' => 'metrics-effect', 'step' => 'metrics-effect', 'transport' => 'agent',
            'name' => $parts[1] === 'node_exporter' ? 'metrics.node-exporter' : 'metrics.cadvisor',
            'input' => ['address' => $operation->input['address'], 'metrics_address' => $operation->input['metrics_address'], 'action' => $action],
            'resumable' => true, 'required' => true]]];
    }
    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }
    public function beforeRun(Operation $operation, array $run): void {}
    public function received(Operation $operation, array $run, array $receipt): array { return $receipt; }
    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}
    public function cancellationBoundary(Operation $operation): ?array { return null; }
}
