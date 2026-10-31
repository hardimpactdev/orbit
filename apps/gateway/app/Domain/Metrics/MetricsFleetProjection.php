<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe capture and bounded root result only; no host execution or child admission. */
final readonly class MetricsFleetProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => app(MetricsFleetWorkflowIntent::class)->fromInput($operation->input),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->direct->project($operation, $data, ValidatedData::object($data['result'] ?? []));
    }
}
