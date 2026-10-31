<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the native run; descriptors contain paths and placement, never database bytes. */
final readonly class DatabaseSqliteProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['source', 'target']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code', 'bytes', 'sha256', 'mode', 'state']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
