<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** The admission stores both publication states; only the native terminal result changes records. */
final readonly class NodeRemovalProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['_removal']),
            'execution_secrets' => $operation->input['execution_secrets'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $wasTerminal = $operation->status->terminal();
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), [
                'schema_version', 'node_id', 'removed', 'restored', 'error_code', 'name', 'offline',
                'roles_shed', 'retained_on_node', 'follow_up',
            ]);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($wasTerminal) {
                return $projected;
            }
            $snapshot = ValidatedData::object($operation->input['_node_removal']);
            if ($data['status'] === 'succeeded') {
                $execution = ValidatedData::object($operation->input['_removal']);
                app(NodeRemovalRecords::class)->remove($snapshot, $result, ValidatedData::object($execution['routes']));
            } elseif (in_array($data['status'], ['failed', 'cancelled'], true) && ($result['restored'] ?? false) === true) {
                app(NodeRemovalRecords::class)->restore($snapshot, $operation->input['route_prior_states'] ?? []);
            }

            return $projected;
        });
    }
}
