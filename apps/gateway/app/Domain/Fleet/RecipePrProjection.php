<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe admitted GitHub intent and bounded native outcome; no external effects. */
final readonly class RecipePrProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha', 'author', 'required_check', 'deployed_recipe']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['completed', 'outcome', 'url', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
