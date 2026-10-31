<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public intent and bounded result recording. Swamp owns merge policy. */
final readonly class DependencyMergeProjection implements DirectOperationProjection
{
    public const int WorkerNodeId = 209;

    public function input(Operation $operation): array
    {
        return array_diff_key($operation->input, ['token' => true]);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['merged', 'head_sha', 'merge_sha', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
