<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** The admitted verifier is stored only after the native end check proved the key on the Node; the vault copy goes at the end. */
final readonly class NodeAgentRekeyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $admitted = ValidatedData::object($operation->input['_agent_rekey']);

        return ['intent' => Arr::except($admitted, ['agent_key']), 'execution_secrets' => ['agent_key' => ValidatedData::object($admitted['agent_key'])]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $admitted = ValidatedData::object($operation->input['_agent_rekey']);
        $result = match ($status) {
            OperationStatus::Succeeded => ['rekeyed' => true, 'restarted' => true],
            OperationStatus::Failed => ['rekeyed' => false, 'error_code' => $data['error_code']],
            default => [],
        };

        $projected = DB::transaction(function () use ($operation, $data, $status, $result, $admitted): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Succeeded) {
                Node::query()->findOrFail($operation->node_id)->forceFill(['agent_secret_hash' => ValidatedData::string($admitted['verifier_hash'])])->save();
            }

            return $projected;
        });
        if ($projected->status->terminal()) {
            $copy = ValidatedData::object($admitted['agent_key']);
            app(SwampScheduleWorkflows::class)->deleteSecret(ValidatedData::string($copy['vault']), ValidatedData::string($copy['key']));
        }

        return $projected;
    }
}
