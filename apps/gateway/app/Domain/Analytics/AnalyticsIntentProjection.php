<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Native execution uses immutable inputs; only the shared terminal reader projects. */
final readonly class AnalyticsIntentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private AnalyticsTrackingIntent $tracking) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['native_enable']),
            'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            if (! $operation->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $this->tracking->assertCurrent($operation);
                $this->tracking->publish($operation);
                $this->tracking->activatePublic($operation);
                $this->tracking->cleanupPlacement($operation);
                $this->tracking->complete($operation);
            }
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []),
                array_flip(['ready', 'enabled', 'readable', 'domain', 'timezone', 'error_code']));

            return $this->direct->project($operation, $data, in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled'], true) ? $result : []);
        });
    }
}
