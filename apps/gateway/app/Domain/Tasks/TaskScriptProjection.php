<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the bounded native script outcome without executing repository code. */
final readonly class TaskScriptProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = ValidatedData::object($data['result'] ?? []);
        if (isset($result['output'])) {
            $result['output'] = app(TaskCheckOutputBuilder::class)->tail(ValidatedData::string($result['output']));
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
