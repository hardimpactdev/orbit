<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsPrimitiveExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        [$name, $input] = match ($operation->kind) {
            'metrics.runtime_converge' => ['metrics.runtime', ['address' => $node->wireguard_ip, 'action' => ['$secret' => 'metrics.runtime', 'id' => $node->id]]],
            'metrics.runtime_verify' => ['metrics.credentials', ['address' => $node->wireguard_ip, 'action' => ['$secret' => 'metrics.password', 'id' => $node->id]]],
            default => throw new \LogicException('Unknown Metrics primitive.'),
        };

        return ['descriptors' => [['key' => 'metrics-effect', 'step' => 'metrics-effect', 'transport' => 'agent', 'name' => $name, 'input' => $input, 'resumable' => true, 'required' => true]]];
    }

    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($operation->kind === 'metrics.runtime_verify' && ($receipt['verified'] ?? null) !== true) {
            return ['verified' => false, 'error_code' => 'metrics.credentials_unverified'];
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
