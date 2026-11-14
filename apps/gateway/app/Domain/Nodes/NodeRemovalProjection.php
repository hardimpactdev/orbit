<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Routes\RouteExecutionProof;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Terminal database projection only; no live input endpoint or host verification. */
final readonly class NodeRemovalProjection implements DirectOperationProjection, DirectOperationTimeout
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $intent = ValidatedData::object($operation->input['intent']);
            $offline = NativeRunResult::stepStatus($raw, 'Log in to departing Node-0') !== 'succeeded';
            $result = ($data['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($raw, 'Confirm Node removal') === 'succeeded'
                ? ['schema_version' => 1, 'node_id' => $intent['node_id'], 'name' => $intent['name'], 'removed' => true,
                    'offline' => $offline, 'roles_shed' => array_column(RouteExecutionProof::objects($intent['roles']), 'role'),
                    'retained_on_node' => [...ValidatedData::stringList($intent['preserved_on_node']), ...($offline ? ValidatedData::stringList($intent['retained_on_node']) : [])],
                    'follow_up' => $offline ? 'Discard the Node or clear the reported retained artifacts when it is reachable.' : null]
                : array_intersect_key($raw, array_flip(['error_code']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $snapshot = ValidatedData::object($operation->input['_node_removal']);
            if ($data['status'] === 'succeeded' && ($result['removed'] ?? false) === true) {
                app(NodeRemovalRecords::class)->remove($snapshot, $result);
            } elseif (in_array($data['status'], ['failed', 'cancelled', 'interrupted'], true)) {
                app(NodeRemovalRecords::class)->fail($snapshot, ValidatedData::nullableString($data['error_code'] ?? null));
            }

            return $projected;
        });
    }

    public function timedOut(Operation $operation): void
    {
        app(NodeRemovalRecords::class)->fail(ValidatedData::object($operation->input['_node_removal']), $operation->error_code);
    }
}
