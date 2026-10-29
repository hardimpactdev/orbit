<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

final readonly class OperationGatewayTransport implements OperationSecretOutputTransport
{
    public function __construct(private OperationGatewayCommands $commands) {}

    public function schema(Operation $operation, array $run): array
    {
        return $this->commands->get($run['name'])->schema();
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->commands->get($run['name'])->run($operation, $input);
    }
}
