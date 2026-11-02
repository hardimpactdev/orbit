<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Only the admitted revision is completed, from the native child's public receipt. */
final readonly class ConnCleanupProjection implements DirectOperationProjection, NativeOperationProjection
{
    public function __construct(private DirectOperationResultProjection $projection) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return $this->projection->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        if ($status === OperationStatus::Succeeded) {
            $raw = ValidatedData::object($data['result'] ?? []);
            $child = ValidatedData::object($raw['revocation_receipts'] ?? []);
            $step = ValidatedData::object($child['Read session revocations'] ?? []);
            $receipt = json_decode(ValidatedData::string($step['stdout'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($receipt) || ($receipt['valid'] ?? false) !== true || ! is_int($receipt['revoked'] ?? null) || $receipt['revoked'] < 0) {
                throw new ResourceOperationException('operation.result_invalid', 'The native session revocation receipt is incomplete.', 422);
            }
            $data['result'] = ['backend' => 'revoked', 'revoked_sessions' => $receipt['revoked']];
        } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
            $data['error_code'] = 'conn.revocation_failed';
            $data['result'] = ['error_code' => 'conn.revocation_failed'];
        } else {
            $data['result'] = [];
        }

        return $this->projection->apply($operation, $data);
    }
}
