<?php

declare(strict_types=1);

namespace App\Domain\Observation;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Record one native read-only result. No Activity, job or Redis mutations. */
final readonly class ObservationMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, array_intersect_key(Arr::array($data, 'result', []), array_flip(['eligible', 'uptime_seconds', 'load', 'horizon_metrics', 'unavailable'])));
    }
}
