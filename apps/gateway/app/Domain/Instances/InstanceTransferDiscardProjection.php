<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** The owning admission records intent; Swamp discards the destination locally. */
final readonly class InstanceTransferDiscardProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ValidatedData::object($operation->state['transfer_discard_intent'] ?? null);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $valid = match ($status) {
            OperationStatus::Succeeded => $code === null && $result === ['discarded' => true],
            OperationStatus::Failed => $code !== null && $result === ['error_code' => $code],
            OperationStatus::Running, OperationStatus::Cancelled => $code === null && $result === [],
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'Destination discard requires a bounded cleanup result or refusal.', 422);
        }

        return $this->direct->project($operation, $data, $result, 'transfer-discard-checkout');
    }
}
