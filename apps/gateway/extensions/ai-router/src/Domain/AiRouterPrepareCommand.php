<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterOperationAction;

final readonly class AiRouterPrepareCommand implements OperationGatewayCommand
{
    public function __construct(private AdmitAiRouterOperationAction $intent) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => new \stdClass];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $this->intent->prepare($operation);

        return ['prepared' => true];
    }
}
