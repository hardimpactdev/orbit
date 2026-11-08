<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The phase prints `{revision, dnsmasq}` (plus `skipped` when a newer publication already held the change) or `{error_code}` in its
 * terminal step; after a failure that changed the Node it adds `recovered` (and `cause` when the recovery failed). The shared reader parses that stdout into `$data['result']` and takes the failed run's `error_code` from it.
 */
final readonly class DnsPublishProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $reported = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['revision', 'dnsmasq', 'skipped']));
        $result = match ($status) {
            OperationStatus::Succeeded => $reported,
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => $code, ...array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['recovered', 'cause']))],
            default => [],
        };

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
