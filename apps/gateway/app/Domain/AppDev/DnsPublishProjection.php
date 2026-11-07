<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/** A succeeded run records the revision it installed or found superseded; a failed run records its error code. */
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
        $dns = ValidatedData::object(ValidatedData::object($operation->input['intent'])['dns']);
        $result = match ($status) {
            OperationStatus::Succeeded => ['revision' => $dns['revision']],
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => $code],
            default => [],
        };

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
