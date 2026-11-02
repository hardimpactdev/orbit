<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The public verifier is stored only from the bound native terminal result. */
final readonly class NodeAgentRekeyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['_agent_rekey'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $raw = ValidatedData::object($data['result'] ?? []);
        $verifier = $raw['verifier_hash'] ?? null;
        if ($status === OperationStatus::Succeeded && (($raw['rekeyed'] ?? null) !== true
            || ! is_string($verifier) || preg_match('/\A[a-f0-9]{64}\z/D', $verifier) !== 1)) {
            throw new ResourceOperationException('operation.result_invalid', 'Native recovery must return its public verifier.', 422);
        }
        $result = match ($status) {
            OperationStatus::Succeeded => ['rekeyed' => true, 'restarted' => true],
            OperationStatus::Failed => ['rekeyed' => false, 'error_code' => $data['error_code']],
            default => [],
        };

        return DB::transaction(function () use ($operation, $data, $status, $result, $verifier): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Succeeded) {
                Node::query()->findOrFail($operation->node_id)->forceFill(['agent_secret_hash' => $verifier])->save();
            }

            return $projected;
        });
    }
}
