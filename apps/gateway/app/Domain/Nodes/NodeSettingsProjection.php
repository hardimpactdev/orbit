<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class NodeSettingsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {

        return ['agent_pin' => $operation->input['agent_pin'],
            'storage' => array_intersect_key($operation->input, array_flip(['root', 'user', 'group', 'create_missing']))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        if ($status === OperationStatus::Succeeded && (($result['prepared'] ?? null) !== true || $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The storage action has no successful result.', 422);
        }
        if (($status === OperationStatus::Failed && ($code !== 'node.settings_root_failed' || ($result['error_code'] ?? null) !== $code)) || ($status !== OperationStatus::Failed && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The storage action needs its refusal code.', 422);
        }
        $result = match ($status) {
            OperationStatus::Succeeded => ['prepared' => true],
            OperationStatus::Failed => ['error_code' => $code],
            default => [],
        };

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $previous = $operation->status;
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'node-storage-root');
            if ($status === OperationStatus::Succeeded && $previous !== OperationStatus::Succeeded) {
                app(NodeSettingsOperationCompletion::class)->complete($projected, OperationOutcome::succeeded($result));
            }

            return $projected;
        });
    }
}
