<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsResetExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        return ['descriptors' => array_map(static fn (string $key): array => ['key' => $key, 'step' => $key, 'transport' => 'agent', 'name' => 'metrics.credentials',
            'input' => ['address' => $node->wireguard_ip, 'action' => ['$secret' => 'metrics.reset', 'id' => $node->id]], 'resumable' => true, 'required' => true], ['credential-apply', 'credential-verify'])];
    }

    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['key'] === 'credential-verify' && ! $operation->steps()->where('name', 'credential-apply')->where('status', 'succeeded')->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'The credential apply receipt is missing.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $run['key'] === 'credential-verify' && ($receipt['verified'] ?? null) !== true
            ? ['verified' => false, 'error_code' => 'metrics.credentials_reset_unverified'] : $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
