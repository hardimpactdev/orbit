<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class NodeAgentRekeyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $snapshot = ValidatedData::object($operation->input['_agent_rekey'] ?? []);
        if (! $operation->status->terminal()) {
            $source = Operation::query()->find(ValidatedData::integer($snapshot['configure_operation_id'] ?? null)) ?? throw NodeAgentRekeyContext::refused();
            $context = app(NodeAgentRekeyContext::class);
            $context->node(ValidatedData::integer($operation->node_id), $source);
            if (($snapshot['node_id'] ?? null) !== $operation->node_id || $context->configuration($source) !== ($snapshot['configuration'] ?? null)) {
                throw NodeAgentRekeyContext::refused();
            }
        }

        return $snapshot;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $code = $data['error_code'] ?? null;
        if (($status === OperationStatus::Succeeded && (count($result) !== 2 || ($result['rekeyed'] ?? null) !== true || ($result['restarted'] ?? null) !== true || $code !== null))
            || ($status === OperationStatus::Failed && (count($result) !== 2 || ($result['rekeyed'] ?? null) !== false || ($result['error_code'] ?? null) !== $code
                || ! in_array($code, ['agent.version_mismatch', 'agent.update_busy', 'swamp.node_worker_memory_low'], true)))
            || (! in_array($status, [OperationStatus::Succeeded, OperationStatus::Failed], true) && ($result !== [] || $code !== null))) {
            throw new ResourceOperationException('operation.result_invalid', 'Agent recovery requires a bounded proven outcome.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'node-agent-rekey');
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $source = Operation::query()->whereKey(ValidatedData::integer($this->input($operation)['configure_operation_id']))->firstOrFail();
                app(NodeAgentRekeyContext::class)->node(ValidatedData::integer($operation->node_id), $source);
                app(OperationStore::class)->change($source, ['state' => [...($source->state ?? []), 'agent_secret_rekey' => [
                    'node_id' => $operation->node_id, 'recovery_operation_id' => $operation->id, 'recovered_at' => now()->toISOString(),
                ]]]);
            }

            return $projected;
        });
    }
}
