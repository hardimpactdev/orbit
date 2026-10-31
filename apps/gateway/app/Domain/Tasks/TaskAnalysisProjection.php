<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Retains native evidence unchanged. No Gateway report judgment or execution. */
final readonly class TaskAnalysisProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = ValidatedData::object($data['result'] ?? []);
        if (isset($result['error'])) {
            unset($result['error_code']);
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
