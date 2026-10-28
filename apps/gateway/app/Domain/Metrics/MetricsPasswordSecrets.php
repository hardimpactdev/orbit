<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsPasswordSecrets implements OperationSecretSource
{
    public function __construct(private MetricsCredentialManager $credentials) {}

    public function name(): string
    {
        return 'metrics.password';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['metrics'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'metrics.runtime_verify' ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'action') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Grafana credential has another owner.', 422);
        }
        $password = $this->credentials->passwordForConvergence(Node::query()->findOrFail($id));

        return new OperationSecretValue(['operation' => 'verify', 'password' => $password]);
    }
}
