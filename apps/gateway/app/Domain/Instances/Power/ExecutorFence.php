<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

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

    /** R2: an issued order without a recorded receipt. Its Operation cannot be cancelled, and its end keeps the lock. */
    public static function holds(Operation $operation): bool
    {
        return self::issued($operation) && $operation->executor_result === null;
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
     * R1: the Operation's outcome from its receipt and its run. A successful receipt succeeds even when its transport failed; the run's
     * own later failure stands only when it is not a transport failure. A boot handoff takes the boot's result. No receipt for an issued
     * order is never a success.
     *
     * @param  array<string, mixed>|null  $receipt
     * @return array{succeeded: bool, error_code: ?string}
     */
    public static function verdict(?array $receipt, bool $ran, ?string $runError, bool $booted): array
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
        if ($ran || $runError === null || str_starts_with($runError, 'executor.')) {
            return ['succeeded' => true, 'error_code' => null];
        }

        return ['succeeded' => false, 'error_code' => $runError];
    }
}
