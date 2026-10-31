<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded state projection only; native DAG steps own execution and uncertainty. */
final readonly class NodeConvergeProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return app(NodeConvergeCommandInput::class)->forOperation($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $artifacts = NodeFootprintProjection::artifacts($result);

        return DB::transaction(function () use ($operation, $data, $result, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $wasTerminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($wasTerminal || ($artifacts === [] && ! isset($result['failed_artifact']))) {
                return $projected;
            }
            app(NodeFootprintProjection::class)->record($operation, NodeConvergeCommandInput::snapshot($operation), $result, $artifacts);

            return $projected;
        });
    }
}
