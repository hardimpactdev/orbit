<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationTransport;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Models\Node;
use App\Models\Operation;

final readonly class OperationRemoteProgramTransport implements OperationTransport
{
    public function __construct(private RemoteProgramRunner $runner, private RemoteProgramCatalog $catalog) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return app(OperationExecutionContract::class)->authorizeRun($operation, $run)['input_schema'] ?? false;
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $result = $this->runner->run(Node::query()->findOrFail($operation->node_id), $this->catalog->program($run['name']), $input);

        return ['outcome' => $result->outcome, 'data' => $result->data, 'log' => $result->log];
    }
}
