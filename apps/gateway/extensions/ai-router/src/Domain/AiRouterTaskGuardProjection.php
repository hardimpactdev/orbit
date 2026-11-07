<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/** A succeeded run records the origin the guard serves; its end checks proved it refuses a source outside the task VM range. */
final readonly class AiRouterTaskGuardProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = match ($status) {
            OperationStatus::Succeeded => ['origin' => ValidatedData::string(ValidatedData::object($operation->input['intent'])['origin'])],
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => ValidatedData::nullableString($data['error_code'] ?? null)],
            default => [],
        };

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
