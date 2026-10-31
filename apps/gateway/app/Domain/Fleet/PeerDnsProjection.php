<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Project the bound native result against the admitted DNS policy. */
final readonly class PeerDnsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['node_id' => $operation->node_id, ...array_intersect_key($operation->input,
            array_flip(['server', 'dns_server', 'public_key', 'domains', 'agent_sha256']))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = app(OperationExecutionContract::class)->result($operation, ValidatedData::object($data['result'] ?? []));
        if ($status === OperationStatus::Succeeded && ($code !== null || isset($result['error_code'])
            || ($result['uplink'] ?? null) !== true
            || ! PeerDnsResult::matches(ValidatedData::string($operation->input['server']), ValidatedData::stringList($operation->input['domains']), $result))) {
            throw new ResourceOperationException('operation.result_invalid', 'The result does not prove the admitted peer DNS policy and usable uplink.', 422);
        }
        if (($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code))
            || ($status !== OperationStatus::Failed && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'A failed peer DNS intent needs its refusal code.', 422);
        }
        $result = match ($status) {
            OperationStatus::Succeeded => $result,
            OperationStatus::Failed => ['error_code' => $code],
            default => [],
        };

        return app(DirectOperation::class)->project($operation, $data, $result, 'peer-dns');
    }
}
