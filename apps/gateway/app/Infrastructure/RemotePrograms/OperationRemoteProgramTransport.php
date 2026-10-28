<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationExecutionContract;
use App\Models\Node;
use App\Models\Operation;

final readonly class OperationRemoteProgramTransport implements OperationEffectTransport
{
    public function __construct(private KeyedRemoteProgramRunner $runner, private RemoteProgramCatalog $catalog) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return app(OperationExecutionContract::class)->authorizeRun($operation, $run)['input_schema'] ?? false;
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $result = $this->runner->run(Node::query()->findOrFail($operation->node_id), $this->catalog->program($run['name']), $input, $run['effect_key'], effect: EffectReceiptMetadata::fromRun($run));

        return ['outcome' => $result->outcome, 'data' => $result->data, 'log' => $result->log];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->runner->receipt(Node::query()->findOrFail($operation->node_id), $this->catalog->program($run['name']), $run['effect_key']);
    }
}
