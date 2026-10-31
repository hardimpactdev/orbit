<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

/** Permits the Caddy compensation only after the recorded service restoration settles. */
final readonly class MetricsCaddyRestoreExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children) {}

    public function context(Operation $operation): array
    {
        return ['descriptors' => [['key' => 'integration-receipt', 'step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt',
            'input' => ['backend' => 'active'], 'resumable' => true, 'required' => true]]];
    }

    public function childContext(Operation $operation): array
    {
        return ['intent' => $operation->input, 'children' => [['key' => 'service-caddy-restore', 'kind' => 'route.caddy_converge',
            'node_id' => MetricsIntent::nodeId($operation), 'input' => ['reason' => 'metrics service rollback']]]];
    }

    public function input(Operation $operation, array $run): array
    {
        return ['backend' => 'active'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->children->requireSucceeded($operation, 'service-caddy-restore');
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->children->requireSucceeded($operation, 'service-caddy-restore');
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
