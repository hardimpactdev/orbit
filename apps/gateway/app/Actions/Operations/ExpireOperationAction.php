<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records Gateway budget expiry and an operator cancel request, never native termination. */
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
            $state = $current->state ?? [];
            $failure = $state['native_failure'] ?? null;
            $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step']
                : ($current->failed_step ?? $current->current_step);
            $seconds = OperationRunBudgets::seconds($current);
            $state['native_timeout'] = ['budget_seconds' => $seconds, 'deadline' => $deadline->toISOString()];
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

            return $current;
        });
    }

    public static function awaitsSettlement(Operation $operation): bool
    {
        $timeout = $operation->state['native_timeout'] ?? null;

        return $operation->status === OperationStatus::Failed && $operation->error_code === 'operation.timed_out'
            && (! is_array($timeout) || (! isset($timeout['native_terminal_observed_at']) && ! isset($timeout['no_run_verified_at'])));
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
