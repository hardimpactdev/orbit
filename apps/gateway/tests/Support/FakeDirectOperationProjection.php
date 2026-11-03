<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Keep shared dispatch tests independent of a production family's declarations. */
final readonly class FakeDirectOperationProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result'] ?? []));
    }
}
