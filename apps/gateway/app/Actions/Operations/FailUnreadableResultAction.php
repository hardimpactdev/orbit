<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\NativeRunTimestamp;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Ends an Operation whose run succeeded while the history shows that its selected end step did not succeed. The run has ended, so
 * no later read can give the result, and waiting for the budget tells the user nothing. Whether the work took effect is not
 * known, so the family's records leave their in-progress state as at a timeout, and the target is free at once.
 */
final readonly class FailUnreadableResultAction
{
    public const string ErrorCode = 'operation.result_unreadable';

    /** @param array<string,mixed> $run The verified terminal history of the bound root run. */
    public function execute(Operation $operation, string $runId, array $run, string $step, ?string $stepStatus): Operation
    {
        return DB::transaction(function () use ($operation, $runId, $run, $step, $stepStatus): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status->terminal()) {
                return $current;
            }
            app(DirectOperation::class)->assertRun($current, $runId);
            $status = is_string($run['status'] ?? null) ? $run['status'] : 'ended';
            $shown = $stepStatus ?? 'absent';
            $state = $current->state ?? [];
            $state['native_failure'] = ['failed_step' => $step, 'stderr_recorded' => false,
                'detail' => "The run {$status}, but its end check \"{$step}\" is {$shown}, so its result cannot be read."];
            app(OperationStore::class)->change($current, [
                'status' => OperationStatus::Failed, 'error_code' => self::ErrorCode, 'lock_key' => null,
                'finished_at' => NativeRunTimestamp::parse($run['completedAt'] ?? null) ?? now(),
                'current_step' => null, 'failed_step' => $step, 'state' => $state,
                'result' => [...($current->result ?? []), 'error_code' => self::ErrorCode],
            ]);
            $projection = app(DirectOperation::class)->projection($current);
            if ($projection instanceof DirectOperationTimeout) {
                $projection->timedOut($current);
            }

            return $current;
        });
    }
}
