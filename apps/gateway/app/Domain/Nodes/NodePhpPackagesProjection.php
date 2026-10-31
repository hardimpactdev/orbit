<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Packages have no additional Gateway state to execute or reconcile. */
final readonly class NodePhpPackagesProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['packages' => $operation->input['packages']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['outcome', 'stale_masters', 'deferred', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
