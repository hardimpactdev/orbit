<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationOverdueAlerts;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskVmAdmission;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Marks soft-limit overruns overdue; only the hard limit records expiry and asks Swamp to stop the bound run. */
final readonly class ExpireOperationAction
{
    /** @param array<string,mixed>|null $nativeRun A run just verified by the shared native reader. */
    public function execute(Operation $operation, ?array $nativeRun = null): Operation
    {
        $deadline = OperationRunBudgets::deadline($operation);
        $soft = OperationRunBudgets::softDeadline($operation);
        if ($operation->status->terminal() || ! DirectOperation::owns($operation)
            || (($deadline === null || $deadline->isFuture()) && ($soft === null || $soft->isFuture() || isset($operation->state['overdue_since'])))) {
            return $operation;
        }

        if ($deadline !== null && ! $deadline->isFuture() && isset($operation->state['swamp_run_id']) && $nativeRun === null) {
            try {
                $nativeRun = app(SwampCommandStep::class)->readProgress($operation);
            } catch (ResourceOperationException) {
                // No hard-expiry proof: the next pass reads again. Soft-limit alerts still apply.
            }
            $operation->refresh();
        }

        return DB::transaction(function () use ($operation, $nativeRun): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status->terminal()) {
                return $current;
            }
            app(OperationOverdueAlerts::class)->raise($current);
            $deadline = OperationRunBudgets::deadline($current);
            if ($deadline === null || $deadline->isFuture()) {
                return $current;
            }
            if (isset($current->state['swamp_run_id']) && ($nativeRun['id'] ?? null) !== $current->state['swamp_run_id']) {
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
            $seconds = OperationRunBudgets::hardSeconds($current);
            $state['native_timeout'] = ['budget_seconds' => $seconds, 'deadline' => $deadline->toISOString()];
            // What reopen() restores when the bound run turns out to have met its budget.
            $state['native_timeout_restore'] = ['cancel_requested' => $current->cancel_requested_at !== null,
                'native_failure' => is_array($failure) && is_string($failure['detail'] ?? null)];
            if (! is_array($failure) || ! is_string($failure['detail'] ?? null)) {
                $state['native_failure'] = ['failed_step' => $step,
                    'detail' => "The Operation exceeded its {$seconds}-second hard limit without a step transition.", 'stderr_recorded' => false];
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
            && is_array($timeout) && ! isset($timeout['native_terminal_status']);
    }

    public static function awaitsSettlement(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Failed && $operation->error_code === 'operation.timed_out' && $operation->lock_key !== null;
    }

    /**
     * Undo an expiry whose root was not bound yet, once its exact native terminal met the bound kind's budget.
     * The admission deadline (600 s) applies until the start reply binds the run; a slow reply must not turn a
     * run that met its budget into a failure. The caller then projects the terminal as usual.
     */
    public function reopen(Operation $operation, string $runId): Operation
    {
        return DB::transaction(function () use ($operation, $runId): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! self::expiredUnsettled($current)) {
                return $current;
            }
            app(DirectOperation::class)->assertRun($current, $runId);
            $state = $current->state ?? [];
            $restore = is_array($state['native_timeout_restore'] ?? null) ? $state['native_timeout_restore'] : [];
            unset($state['native_timeout'], $state['native_timeout_restore']);
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

    /** Called only after the shared reader verifies the exact terminal native root identity. */
    public function settle(Operation $operation, string $runId, string $status): Operation
    {
        if (! in_array($status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new \LogicException('A target hold requires terminal native proof.');
        }

        return DB::transaction(function () use ($operation, $runId, $status): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! self::awaitsSettlement($current)) {
                return $current;
            }
            app(DirectOperation::class)->assertRun($current, $runId);
            $timeout = $current->state['native_timeout'] ?? [];
            app(OperationStore::class)->change($current, ['lock_key' => null,
                'state' => [...($current->state ?? []), 'native_timeout' => [
                    ...(is_array($timeout) ? $timeout : []), 'native_terminal_status' => $status,
                    'native_terminal_observed_at' => now()->toISOString(),
                ]],
            ]);

            return $current;
        });
    }
}
