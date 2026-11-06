<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Settles an open Operation whose kind has left the registry. The reconciler and the cancel path serve registered kinds only, so
 * such an Operation would stay open for ever and hold its target. It has no run to ask Swamp about: a cancel request ends it, and
 * otherwise its budget does.
 */
final readonly class RetiredOperationSettlement
{
    public function __construct(private OperationStore $store) {}

    public static function retired(Operation $operation): bool
    {
        return ! array_key_exists($operation->kind, OperationKinds::definitions());
    }

    /** Cancelled when a cancel was requested, failed at its budget, otherwise unchanged. Either end frees the target. */
    public function settle(Operation $operation): Operation
    {
        return DB::transaction(function () use ($operation): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status->terminal() || ! self::retired($current)) {
                return $current;
            }
            if ($current->cancel_requested_at !== null) {
                $this->store->change($current, ['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null,
                    'current_step' => null, 'failed_step' => null, 'lock_key' => null]);

                return $current;
            }
            $deadline = OperationRunBudgets::deadline($current);
            if ($deadline === null || $deadline->isFuture()) {
                return $current;
            }
            $this->store->change($current, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out',
                'current_step' => null, 'lock_key' => null, 'result' => [...($current->result ?? []), 'error_code' => 'operation.timed_out']]);

            return $current;
        });
    }

    /** Settles every open Operation of a retired kind that is ready. Returns how many it ended. */
    public function sweep(): int
    {
        $ended = 0;
        $open = [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting];
        foreach (Operation::query()->whereIn('status', $open)->whereNotIn('kind', array_keys(OperationKinds::definitions()))->lazyById() as $operation) {
            if ($this->settle($operation)->status->terminal()) {
                $ended++;
            }
        }

        return $ended;
    }
}
