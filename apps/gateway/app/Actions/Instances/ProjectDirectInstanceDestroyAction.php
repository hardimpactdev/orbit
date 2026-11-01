<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\InstanceLifecycleInput;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceTransfer;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects the admitted record set after the bound native DAG succeeds. */
final readonly class ProjectDirectInstanceDestroyAction
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     */
    public function execute(array $input, array $result): InstanceRemoval
    {
        return DB::transaction(function () use ($input): InstanceRemoval {
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($input['removal_id']));
            if ($removal->status === InstanceRemovalStatus::Completed) {
                return $removal->load('members');
            }
            $saved = $removal->native_intent ?? $input;
            $detail = ValidatedData::object($saved['direct_destroy']);
            $runtime = ValidatedData::object($detail['runtime']);
            $processIds = ValidatedData::integerList(array_column(InstanceLifecycleInput::members(['members' => $runtime['processes']]), 'process_id'));
            $scheduleIds = ValidatedData::stringList(array_column(InstanceLifecycleInput::members(['members' => $runtime['schedules']]), 'id'));
            $instanceIds = $removal->members()->pluck('instance_id')->all();
            if (Process::query()->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instanceIds)->whereNotIn('id', $processIds)->exists()
                || Schedule::query()->whereIn('target_type', Instance::morphTypes())->whereIn('target_id', $instanceIds)->whereNotIn('id', $scheduleIds)->exists()) {
                throw new ResourceOperationException('instance.removal_conflict', 'Unadmitted runtime still owns the Instance.', 409);
            }
            // Ownership is a record invariant, not a second validation of native execution.
            $database = ValidatedData::object($detail['database']);
            $ids = ValidatedData::integerList($database['connection_ids']);
            if (DatabaseConnectionTarget::query()->whereIn('database_connection_id', $ids)->whereNotIn('instance_id', $instanceIds)->exists()
                || DatabaseConnection::query()->whereIn('id', $ids)->where(fn ($query) => $query->whereNull('owner_instance_id')->orWhereNotIn('owner_instance_id', $instanceIds))->exists()) {
                throw new ResourceOperationException('database.removal_conflict', 'An admitted database has a foreign owner or target.', 409);
            }
            DatabaseConnectionTarget::query()->whereIn('database_connection_id', $ids)->delete();
            DatabaseConnection::query()->whereIn('id', $ids)->delete();
            foreach ($ids as $id) {
                $this->deleted(RecordEventType::DatabaseDeleted, $id);
            }
            Schedule::query()->whereKey($scheduleIds)->delete();
            Process::query()->whereKey($processIds)->delete();
            $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
            foreach ($removal->members()->lockForUpdate()->get() as $member) {
                $member->update(['source_prepared_at' => $member->source_prepared_at ?? now()]);
                foreach ($member->route_ids ?? [] as $routeId) {
                    Route::query()->whereKey($routeId)->delete();
                    $this->deleted(RecordEventType::RouteDeleted, $routeId);
                }
                $member->update(['route_cleared_at' => now(), 'route_outcome' => $member->route_id === null ? 'none' : 'deleted',
                    'source_finalized_at' => now(), 'finalization_receipt' => 'native:'.$removal->id,
                    'runtime_cleaned_at' => now()]);
                InstanceTransfer::query()->where('instance_id', $member->instance_id)->closed()->update(['instance_id' => null]);
                Instance::query()->whereKey($member->instance_id)->delete();
                $member->update(['row_deleted_at' => now()]);
                $this->deleted(RecordEventType::InstanceDeleted, $member->instance_id);
            }
            foreach ($processIds as $id) {
                $this->deleted(RecordEventType::ProcessDeleted, $id);
            }
            foreach ($scheduleIds as $id) {
                $this->deleted(RecordEventType::ScheduleDeleted, $id);
            }
            $removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);

            return $removal->refresh()->load('members');
        });
    }

    private function deleted(RecordEventType $type, int|string $id): void
    {
        DB::afterCommit(fn () => $this->broadcaster->broadcast($type, $id, ['id' => $id]));
    }
}
