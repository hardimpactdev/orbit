<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The shared terminal reader owns delivery; this class changes only stored intent. */
final readonly class AnalyticsDisableProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private AnalyticsTrackingIntent $tracking) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['native_disable']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            if (! $operation->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $this->tracking->complete($operation);
            }
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []),
                array_flip(['ready', 'enabled', 'detached', 'domain', 'timezone']));

            return $this->direct->project($operation, $data, $result);
        });
    }
}
