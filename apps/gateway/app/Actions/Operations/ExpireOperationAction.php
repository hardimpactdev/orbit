<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\TaskVmAdmission;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records Gateway budget expiry and a cancel request, and asks Swamp to stop the bound run; Swamp ends it. */
final readonly class ExpireOperationAction
{
    public function execute(Operation $operation): Operation
    {
        $deadline = OperationRunBudgets::deadline($operation);
        if ($operation->status->terminal() || $deadline === null || $deadline->isFuture() || ! DirectOperation::owns($operation)) {
            return $operation;
        }

        return DB::transaction(function () use ($operation): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $deadline = OperationRunBudgets::deadline($current);
            if ($current->status->terminal() || $deadline === null || $deadline->isFuture()) {
                return $current;
            }
            if (isset($current->input['capacity_request'])) {
                app(TaskVmAdmission::class)->fail($current, 'tasks.capacity_timeout', 'No place became free within the two-hour admission budget.');

                return $current;
            }
            $state = $current->state ?? [];
            $failure = $state['native_failure'] ?? null;
            $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step']
                : ($current->failed_step ?? $current->current_step);
            $seconds = OperationRunBudgets::seconds($current);
            $state['native_timeout'] = ['budget_seconds' => $seconds, 'deadline' => $deadline->toISOString()];
            // What terminal reconciliation restores once the exact native result is available.
            $state['native_timeout_restore'] = ['cancel_requested' => $current->cancel_requested_at !== null,
                'native_failure' => is_array($failure) && is_string($failure['detail'] ?? null)];
            if (! is_array($failure) || ! is_string($failure['detail'] ?? null)) {
                $state['native_failure'] = ['failed_step' => $step,
                    'detail' => "The Operation exceeded its {$seconds}-second run budget.", 'stderr_recorded' => false];
            }
            app(OperationStore::class)->change($current, [
                'status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out',
                'cancel_requested_at' => $current->cancel_requested_at ?? now(),
                'current_step' => null, 'failed_step' => $step, 'state' => $state,
                'result' => [...($current->result ?? []), 'error_code' => 'operation.timed_out'],
            ]);
            $projection = app(DirectOperation::class)->projection($current);
            if ($projection instanceof DirectOperationTimeout) {
                $projection->timedOut($current);
            }
            // A run that outlives its Operation holds its behavior locks and its SSH session; a dead VM would hold them forever.
            app(NativeRunCancellation::class)->request($current);

            return $current;
        });
    }

    /** How long a timed-out Operation may hold its target while Swamp has not proven that its run ended. */
    public const int HoldMinutes = 60;

    /**
     * Frees the target of a timed-out Operation after `HoldMinutes` without proof that its run ended: a run that was asked to cancel an
     * hour ago and still has no terminal is lost, and a lost run must not hold its target for ever. Returns how many it freed.
     */
    public function releaseStaleHolds(): int
    {
        $freed = 0;
        $stale = Operation::query()->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')->whereNotNull('lock_key')
            ->where('finished_at', '<', now()->subMinutes(self::HoldMinutes))->pluck('id');
        foreach ($stale as $id) {
            DB::transaction(function () use ($id, &$freed): void {
                $current = Operation::query()->lockForUpdate()->whereKey($id)->firstOrFail();
                if (! self::awaitsSettlement($current)) {
                    return;
                }
                $timeout = $current->state['native_timeout'] ?? [];
                app(OperationStore::class)->change($current, ['lock_key' => null,
                    'state' => [...($current->state ?? []), 'native_timeout' => [
                        ...(is_array($timeout) ? $timeout : []), 'native_terminal_status' => 'unproven',
                        'native_terminal_observed_at' => now()->toISOString(),
                    ]],
                ]);
                $freed++;
            });
        }

        return $freed;
    }

    /** Expired by the Gateway with no native terminal recorded yet; a lock-holding one also awaits settlement. */
    public static function expiredUnsettled(Operation $operation): bool
    {
        $timeout = $operation->state['native_timeout'] ?? null;

        return $operation->status === OperationStatus::Failed && $operation->error_code === 'operation.timed_out'
            && is_array($timeout) && (! isset($timeout['native_terminal_status']) || $timeout['native_terminal_status'] === 'unproven');
    }

    public static function awaitsSettlement(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Failed && $operation->error_code === 'operation.timed_out' && $operation->lock_key !== null;
    }

    /** A native cancellation after expiry keeps its timeout meaning unless the user already asked to cancel. */
    public static function deadlineCancellation(Operation $operation): bool
    {
        $timeout = $operation->state['native_timeout'] ?? null;
        $restore = ValidatedData::object($operation->state['native_timeout_restore'] ?? []);

        return is_array($timeout) && ($timeout['cancel_requested_before_deadline'] ?? $restore['cancel_requested'] ?? false) !== true;
    }

    /**
     * Replace a provisional expiry once the exact native root has a terminal result.
     * The caller projects that terminal through its normal family projection, including a restoration that ended after the deadline.
     */
    public function reopen(Operation $operation, string $runId, string $nativeStatus): Operation
    {
        return DB::transaction(function () use ($operation, $runId, $nativeStatus): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! self::expiredUnsettled($current)) {
                return $current;
            }
            app(DirectOperation::class)->assertRun($current, $runId);
            $state = $current->state ?? [];
            $restore = is_array($state['native_timeout_restore'] ?? null) ? $state['native_timeout_restore'] : [];
            $timeout = ValidatedData::object($state['native_timeout'] ?? []);
            unset($state['native_timeout'], $state['native_timeout_restore']);
            $state['native_timeout'] = [...$timeout, 'cancel_requested_before_deadline' => ($restore['cancel_requested'] ?? false) === true,
                'native_terminal_status' => $nativeStatus, 'native_terminal_observed_at' => now()->toISOString()];
            if (($restore['native_failure'] ?? true) !== true) {
                unset($state['native_failure']);
            }
            $result = $current->result ?? [];
            unset($result['error_code']);
            app(OperationStore::class)->change($current, [
                'status' => OperationStatus::Running, 'finished_at' => null, 'error_code' => null, 'failed_step' => null,
                'cancel_requested_at' => ($restore['cancel_requested'] ?? true) === true ? $current->cancel_requested_at : null,
                'state' => $state, 'result' => $result,
            ]);

            return $current;
        });
    }
}
