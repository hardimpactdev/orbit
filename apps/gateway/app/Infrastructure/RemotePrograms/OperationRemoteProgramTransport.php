<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
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
        $result = $this->runner->run((Node::query()->find($operation->node_id) ?? throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409)), $this->catalog->program($run['name']), $input, $run['effect_key'], effect: EffectReceiptMetadata::fromRun($run));

        return ['outcome' => $result->outcome, 'data' => $result->data, 'log' => $result->log];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->runner->receipt((Node::query()->find($operation->node_id) ?? throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409)), $this->catalog->program($run['name']), $run['effect_key']);
    }
}
