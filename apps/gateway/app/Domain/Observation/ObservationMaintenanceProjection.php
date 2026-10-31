<?php

declare(strict_types=1);

namespace App\Domain\Observation;

use App\Actions\Activities\FinalizeInterruptedActivitiesAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Supplies captured public intent and applies Swamp-selected state changes. */
final readonly class ObservationMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => Arr::only($operation->input, ['routine', 'tasks_enabled', 'activity_seconds',
            'batch_hours', 'failed_hours', 'redis', 'activities', 'batches', 'failed']),
            'execution_secrets' => $operation->input['execution_secrets'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $operation->result ?? []);
            }
            $result = array_intersect_key(Arr::array($data, 'result', []), array_flip(['snapshot_jobs', 'snapshot_queues']));
            if (in_array($data['status'] ?? null, ['succeeded', 'failed'], true)) {
                $native = Arr::array($data, 'result', []);
                $result['finalized'] = FinalizeInterruptedActivitiesAction::finalize(Activity::query()->whereKey(Arr::array($native, 'activity_ids', [])), Carbon::parse(Arr::string($data, 'finished_at')));
                $result['pruned_batches'] = DB::connection(config()->string('queue.batching.database'))->table(config()->string('queue.batching.table'))->whereIn('id', Arr::array($native, 'batch_ids', []))->delete();
                $result['pruned_failed'] = DB::connection(config()->string('queue.failed.database'))->table(config()->string('queue.failed.table'))->whereIn('id', Arr::array($native, 'failed_ids', []))->delete();
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }
}
