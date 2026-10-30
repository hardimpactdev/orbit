<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Bounded public cleanup intent and result; native Swamp owns execution. */
final readonly class DatabaseCleanupProjection implements DirectOperationProjection
{
    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(app(OperationExecutionContract::class)->result($operation, ValidatedData::object($data['result'] ?? [])), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && (($result['backend'] ?? null) !== 'absent' || $code !== null || isset($result['error_code'])))
            || ($status === 'failed' && ($code === null || ($result['error_code'] ?? null) !== $code))) {
            throw new ResourceOperationException('operation.result_invalid', 'The cleanup needs its bounded Swamp outcome.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
    }
}
