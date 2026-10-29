<?php

declare(strict_types=1);

namespace App\Domain\Instances\VpEnvironment;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class InstanceVpEnvironmentSecrets implements OperationSecretSource
{
    public function __construct(private InstanceVpEnvironmentMaterial $material) {}

    public function name(): string
    {
        return 'instance.vp_environment';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'instance.prepare_vp_environment' && $operation->target_type === 'instance' ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $key !== 'vp.install' || $parameter !== 'npmrc') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The registry configuration is not part of this runtime intent.', 422);
        }
        $npmrc = $this->material->npmrc($operation);
        preg_match_all('/^[^#;\r\n=]+=(.+)$/m', $npmrc, $matches);

        return new OperationSecretValue($npmrc, $matches[1]);
    }
}
