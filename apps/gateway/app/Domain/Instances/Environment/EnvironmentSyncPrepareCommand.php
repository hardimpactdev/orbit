<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** Reads original transfer configuration and records only missing-key intent in the Gateway. */
final readonly class EnvironmentSyncPrepareCommand implements OperationGatewayCommand
{
    public function __construct(private EnvironmentSyncMaterial $material) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id'],
            'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $this->material->prepare($operation);

        return ['prepared' => true, 'no_op' => $this->material->onlyUrls($operation) || ($this->material->transfer($operation) !== null
            && $this->material->snapshot($this->material->context($operation))->keyCount() === 0)];
    }
}
