<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationTimeoutSettlement;
use App\Domain\Operations\DirectOperationUnstartedTerminal;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\TaskVmAdmission;
use App\Models\Operation;
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
            $unstarted = DirectOperation::unstarted($current);
            $failure = $state['native_failure'] ?? null;
            $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step']
                : ($current->failed_step ?? $current->current_step);
            $seconds = OperationRunBudgets::seconds($current);
            $state['native_timeout'] = ['budget_seconds' => $seconds, 'deadline' => $deadline->toISOString()];
            // What reopen() restores when the bound run turns out to have met its budget.
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
            if ($unstarted && $projection instanceof DirectOperationUnstartedTerminal) {
                app(OperationStore::class)->change($current, ['lock_key' => null,
                    'state' => [...($current->state ?? []), 'native_timeout' => [
                        ...$state['native_timeout'], 'native_terminal_status' => 'unstarted',
                    ]]]);
                $projection->unstartedTerminal($current);

                return $current;
            }
            if ($projection instanceof DirectOperationTimeout) {
                $projection->timedOut($current);
            }
            // A run that outlives its Operation holds its behavior locks and its SSH session; a dead VM would hold them forever.
            app(NativeRunCancellation::class)->request($current);

            return $current;
        });
    }

    /** How long to await native terminal proof before releasing a timed-out Operation’s target hold and deferred admission. */
    public const int HoldMinutes = 60;

    /**
     * Frees the target of a timed-out Operation after `HoldMinutes` without proof that its run ended: a run that was asked to cancel an
     * hour ago and still has no terminal is lost, and a lost run must not hold its target for ever. Also settles expired runs without a target lock. Returns how many it settled.
     */
    public function releaseStaleHolds(): int
    {
        $freed = 0;
        $stale = Operation::query()->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')
            ->where(fn ($query) => $query->whereNotNull('lock_key')->orWhere(fn ($query) => $query
                ->whereNotNull('state->native_timeout')->whereNull('state->native_timeout->native_terminal_status')))
            ->where('finished_at', '<', now()->subMinutes(self::HoldMinutes))->pluck('id');
        foreach ($stale as $id) {
            DB::transaction(function () use ($id, &$freed): void {
                $current = Operation::query()->lockForUpdate()->whereKey($id)->firstOrFail();
                if (! self::awaitsSettlement($current) && ! self::expiredUnsettled($current)) {
                    return;
                }
                $timeout = $current->state['native_timeout'] ?? [];
                app(OperationStore::class)->change($current, ['lock_key' => null,
                    'state' => [...($current->state ?? []), 'native_timeout' => [
                        ...(is_array($timeout) ? $timeout : []), 'native_terminal_status' => 'unproven',
                        'native_terminal_observed_at' => now()->toISOString(),
                    ]],
                ]);
                $this->settleDeferredAdmission($current);
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
            if (! self::awaitsSettlement($current) && ! self::expiredUnsettled($current)) {
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
            $this->settleDeferredAdmission($current);

            return $current;
        });
    }

    private function settleDeferredAdmission(Operation $operation): void
    {
        if (! DirectOperation::owns($operation)) {
            return;
        }
        $projection = app(DirectOperation::class)->projection($operation);
        if ($projection instanceof DirectOperationTimeoutSettlement) {
            $projection->timeoutSettled($operation);
        }
    }
}
