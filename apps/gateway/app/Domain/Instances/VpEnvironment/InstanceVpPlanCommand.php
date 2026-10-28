<?php

declare(strict_types=1);

namespace App\Domain\Instances\VpEnvironment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

final readonly class InstanceVpPlanCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceVpEnvironmentMaterial $material) {}

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['instance_id'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->material->plan($operation);
    }
}
