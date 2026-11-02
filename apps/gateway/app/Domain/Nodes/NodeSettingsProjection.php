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
        return ['intent' => ['ssh' => $operation->input['ssh'],
            'storage' => array_intersect_key($operation->input, array_flip(['root', 'user', 'group']))]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        // A refusal or failure on Node 12 is the documented settings code; its stderr carries the reason.
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        if ($status === OperationStatus::Failed && in_array($failure['failed_step'] ?? null, ['Create the apps folder', 'Check owner and mode', 'Prove the apps folder'], true)) {
            $code = $data['error_code'] = 'node.settings_root_failed';
        }
        $result = ValidatedData::object($data['result'] ?? []);
        // Swamp's run data: the native assert compared the folder's stat with the intent.
        if ($status === OperationStatus::Succeeded && (($result['steps']['Prove the apps folder']['status'] ?? null) !== 'succeeded' || $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The storage action has no successful result.', 422);
        }
        if (($status === OperationStatus::Failed && $code === null) || ($status !== OperationStatus::Failed && $code !== null)) {
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
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === OperationStatus::Succeeded && $previous !== OperationStatus::Succeeded) {
                app(NodeSettingsOperationCompletion::class)->complete($projected, OperationOutcome::succeeded($result));
            }

            return $projected;
        });
    }
}
