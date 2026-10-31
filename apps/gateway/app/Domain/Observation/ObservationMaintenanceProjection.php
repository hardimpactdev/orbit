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

/** Reads Gateway records and applies Swamp-selected state changes; no execution policy. */
final readonly class ObservationMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        unset($input['queue_redis']);
        if ($input['routine'] === 'maintenance') {
            $input['activities'] = Activity::query()->where('status', 'running')->orderBy('id')->get(['id', 'created_at'])->toArray();
        } else {
            $input['batches'] = $input['tasks_enabled'] ? DB::connection(config()->string('queue.batching.database'))->table(config()->string('queue.batching.table'))->get(['id', 'created_at', 'finished_at', 'cancelled_at'])->map(static fn (object $row): array => (array) $row)->all() : [];
            $input['failed'] = DB::connection(config()->string('queue.failed.database'))->table(config()->string('queue.failed.table'))->get(['id', 'failed_at'])->map(static fn (object $row): array => (array) $row)->all();
        }

        return $input;
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
