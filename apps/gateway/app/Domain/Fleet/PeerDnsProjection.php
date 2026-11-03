<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/** A succeeded run records the policy it applied; its end check resolved a private and a public name. */
final readonly class PeerDnsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        $result = match ($status) {
            OperationStatus::Succeeded => array_intersect_key(ValidatedData::object($operation->input['intent']), array_flip(['server', 'domains'])),
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => $code],
            default => [],
        };

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
