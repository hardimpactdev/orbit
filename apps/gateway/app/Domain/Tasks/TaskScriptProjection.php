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
        $native = ValidatedData::object($data['result'] ?? []);
        $result = isset($native['program'])
            ? ValidatedData::object(json_decode(ValidatedData::string($native['program']), true, flags: JSON_THROW_ON_ERROR))
            : [];
        if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true) && isset($result['error_code'])) {
            $data['error_code'] = $result['error_code'];
        }
        if (isset($result['output'])) {
            $result['output'] = app(TaskCheckOutputBuilder::class)->tail(ValidatedData::string($result['output']));
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
