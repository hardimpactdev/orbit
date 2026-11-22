<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorResults;
use App\Models\Operation;

/**
 * The two rules of a signed order's outcome (ADR 0266, "Four rules for every signed outcome"). R1: the durable matching receipt decides
 * what happened to the container; a transport outcome never overrides it. R2: once an order was signed for the Operation, the Instance
 * stays fenced until a terminal receipt exists. Every projection and every end of an Operation asks here.
 */
final class ExecutorFence
{
    /** Set in the transaction that signs an order for the Operation, before the order leaves the Gateway. */
    public const string Issued = 'executor_order_issued';

    /** Set when the Node's journal proved that the expired order never acted (ExecutorWake::reconcile). */
    public const string Absent = 'executor_order_absent';

    /** Set when a receipt or the journal freed an Operation that had ended under its fence: what settled it and when. */
    public const string Settled = 'executor_fence_settled';

    /** @param array<string, mixed>|null $state
     * @return array<string, mixed>
     */
    public static function issue(?array $state): array
    {
        return [...($state ?? []), self::Issued => true];
    }

    /** R2: whether a signed order of the Operation may have reached its Node. */
    public static function issued(Operation $operation): bool
    {
        return ($operation->state[self::Issued] ?? false) === true
            || (ExecutorWake::owns($operation) && ExecutorPowerIntent::managed($operation)
                && (isset($operation->state[ExecutorWake::PublishedAt]) || $operation->executor_accepted_us !== null));
    }

    /**
     * R2: an issued order without a recorded receipt. Its Operation cannot be cancelled, and its end keeps the lock. Only the Node's
     * journal frees an issued order without a receipt: it shows no record of the order after the order expired there (`Absent`).
     */
    public static function holds(Operation $operation): bool
    {
        return self::issued($operation) && $operation->executor_result === null && ($operation->state[self::Absent] ?? false) !== true;
    }

    /**
     * R1 and R2 at the one end of every Operation (OperationStore::change). An issued order without a receipt ends interrupted and keeps
     * the lock. An Operation with a durable receipt ends with the receipt's verdict, whoever wrote the end: the run's report, a refused
     * start, an expired budget or a late receipt (resolve()).
     *
     * @param  array<string, mixed>  $changes  A terminal write.
     * @return array<string, mixed>
     */
    public static function settle(Operation $current, array $changes): array
    {
        if (self::holds($current)) {
            return [...$changes, 'status' => OperationStatus::Interrupted, 'lock_key' => $current->lock_key, 'error_code' => 'executor.result_unknown'];
        }
        $projection = $current->executor_result !== null && DirectOperation::owns($current) ? app(DirectOperation::class)->projection($current) : null;

        return $projection instanceof InstancePower ? $projection->settle($current, $changes) : $changes;
    }

    /** R1/R2: a receipt stored after its Operation ended under the fence ends it again through settle(), which releases the lock. */
    public static function resolve(Operation $operation): void
    {
        $operation->refresh();
        if ($operation->status->terminal() && $operation->lock_key !== null && $operation->executor_result !== null) {
            app(OperationStore::class)->change($operation, ['status' => $operation->status, 'error_code' => $operation->error_code,
                'finished_at' => now(), 'lock_key' => null, 'state' => [...($operation->state ?? []), self::Settled => [
                    'by' => $operation->executor_result['delivery'] ?? 'receipt', 'status' => $operation->executor_result['status'] ?? null, 'at' => now()->toIso8601String()]]]);
        }
    }

    /**
     * An Operation of a signed lifecycle phase (a start or a deploy) that ended under its fence: its Swamp run is over, so only a receipt
     * or the Node's journal can settle it (ExecutorWake::settleFenced). A signed power Operation settles through ExecutorWake::complete.
     */
    public static function heldLifecycle(Operation $operation): bool
    {
        return ! ExecutorWake::owns($operation) && ($operation->state[self::Issued] ?? false) === true && ExecutorWake::endedUnknown($operation)
            && self::lifecycleActions($operation) !== [];
    }

    /** @return list<string> The executor actions the Operation's admitted lifecycle phases could sign. */
    public static function lifecycleActions(Operation $operation): array
    {
        return array_values(array_unique(array_filter([ExecutorPowerIntent::action($operation, 'resume'), ExecutorPowerIntent::action($operation, 'boot')])));
    }

    /**
     * R1: records the first matching receipt on the Operation and returns the durable one. A receipt recorded earlier always wins over a
     * later copy, so a repeated delivery or a failed transport cannot change it.
     *
     * @return array<string, mixed>|null
     */
    public static function record(Operation $operation, string $action, mixed $raw): ?array
    {
        if (($stored = ExecutorResults::matchingReceipt($operation, $action, $operation->executor_result)) !== null) {
            return $stored;
        }
        $receipt = ExecutorResults::matchingReceipt($operation, $action, $raw);
        if ($receipt !== null && $operation->executor_result === null && ! $operation->status->terminal()) {
            $operation->forceFill(['executor_result' => $receipt, 'executor_result_received_us' => (int) now()->format('Uu')])->save();
        }

        return $receipt;
    }

    /**
     * R1: a wake's `failed` receipt with `instance.container_stopped` is no outcome of its own. It hands the stopped container to the
     * ordinary boot, whose own result then decides.
     *
     * @param  array<string, mixed>|null  $receipt
     */
    public static function handsToBoot(?array $receipt): bool
    {
        return ($receipt['action'] ?? null) === 'wake' && ($receipt['status'] ?? null) === 'failed'
            && ($receipt['code'] ?? null) === 'instance.container_stopped';
    }

    /**
     * R3: a receipt that proves the Node no longer holds a freeze: a wake that found the container stopped discards it before it
     * answers, and a done discard removes it.
     *
     * @param  array<string, mixed>|null  $receipt
     */
    public static function discarded(?array $receipt): bool
    {
        return self::handsToBoot($receipt) || (($receipt['action'] ?? null) === 'discard' && ($receipt['status'] ?? null) === 'done');
    }

    /**
     * R1: the Operation's outcome from its receipt and its run. A successful receipt succeeds whatever its run reports, unless the run
     * does work after the executor (`$workAfter`, a lifecycle wrapper) and that work failed. A boot handoff takes the boot's result. No
     * receipt for an issued order is never a success.
     *
     * @param  array<string, mixed>|null  $receipt
     * @return array{succeeded: bool, error_code: ?string}
     */
    public static function verdict(?array $receipt, bool $ran, ?string $runError, bool $booted, bool $workAfter = false): array
    {
        if ($receipt === null) {
            return ['succeeded' => false, 'error_code' => $ran ? 'executor.result_unproven' : $runError];
        }
        if (self::handsToBoot($receipt) && ($booted || $runError === InstanceWake::NestedStopped)) {
            return ['succeeded' => $ran && $booted, 'error_code' => $ran && $booted ? null : ($runError ?? 'instance.container_stopped')];
        }
        if (! ExecutorResults::succeeded($receipt)) {
            return ['succeeded' => false, 'error_code' => ExecutorResults::failureCode($receipt)];
        }
        if (! $workAfter || $ran || $runError === null || str_starts_with($runError, 'executor.')) {
            return ['succeeded' => true, 'error_code' => null];
        }

        return ['succeeded' => false, 'error_code' => $runError];
    }
}
