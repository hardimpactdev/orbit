<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place that turns a recorded intent to stop an Operation into a request to Swamp. The Gateway records and
 * forwards; the native cancellation path stops the run. A run that no step has bound has nothing to cancel.
 */
final readonly class NativeRunCancellation
{
    /** How long after a request the reconciler keeps forwarding it; a run that outlives that is lost. */
    public const int ForwardMinutes = 60;

    /**
     * The steps that copy a clone's database on its database server, per kind, with the bound in seconds that each step's own
     * `timeout` gives it. A cancel ends only the SSH client of such a step: the copy runs on, and a removal could then drop a
     * database the copy still creates. So a cancel waits until the step has ended (docs/reference/operations.md#cancel-an-operation).
     * `NativeRunCancellationTest` ties each name and bound to the workflow files.
     */
    public const array CopySteps = [
        // A clone's create copies no database: its completion does.
        'instance.create' => ['server-database-fill' => 600],
        'instance.complete' => ['server-database-fill' => 600],
    ];

    /** A copy step that still shows `running` this long past its bound has lost its end event: the cancel goes on anyway. */
    public const int CopyMarginSeconds = 60;

    public function __construct(private OperationStore $store) {}

    /**
     * Records the request once on the Operation; the reconciler forwards it until Swamp confirms. Only an operator's cancel
     * records it again, for a request that Swamp never confirmed. `$reconcile`: dispatch a reconciler pass after commit to send it;
     * a caller that sends it itself after its response passes false, so one request is sent once, and the minute pass stays its retry.
     */
    public function request(Operation $operation, bool $again = false, bool $reconcile = true): bool
    {
        // The row as it is under the lock: a step event that landed after the caller loaded the Operation keeps its state keys.
        return DB::transaction(function () use ($operation, $again, $reconcile): bool {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $run = $operation->state['swamp_run_id'] ?? null;
            if (! is_string($run) || ! Str::isUuid($run) || ! DirectOperation::owns($operation)
                || ! app(DirectOperation::class)->usesNativeTerminal($operation)
                || (! $again && isset($operation->state['native_cancel_requested_at']))) {
                return false;
            }
            $state = $operation->state ?? [];
            unset($state['native_cancel_confirmed_at']);
            $this->store->change($operation, ['state' => [...$state, 'native_cancel_requested_at' => now()->toISOString()]]);
            if ($reconcile) {
                ReconcileSwampOperations::dispatch()->afterCommit();
            }

            return true;
        });
    }

    /**
     * Sends a recorded request to Swamp, the one send path for the reconciler and for a cancel request after its response. It reads
     * the row again first: a run that ended, a request already confirmed, or a database copy that started since holds the send.
     * Swamp's confirmation is recorded, unless the request was recorded again while Swamp answered. A failed send leaves the request
     * recorded, and the reconciler sends it again.
     */
    public function forward(Operation $operation): void
    {
        $operation->refresh();
        if (! $this->awaitsForwarding($operation)) {
            return;
        }
        $requested = $operation->state['native_cancel_requested_at'] ?? null;
        $this->logBoundPassed($operation);
        app(CancelSwampRun::class)->execute($operation);
        DB::transaction(function () use ($operation, $requested): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $state = $current->state ?? [];
            if (($state['native_cancel_requested_at'] ?? null) === $requested) {
                $this->store->change($current, ['state' => [...$state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
            }
        });
    }

    /** True while the reconciler must still forward a recorded request: Swamp has not confirmed it, the window is open and the run has no recorded end. */
    public function awaitsForwarding(Operation $operation): bool
    {
        $state = $operation->state ?? [];
        $requested = $state['native_cancel_requested_at'] ?? null;

        return is_string($requested) && ! isset($state['native_cancel_confirmed_at'])
            && Carbon::parse($requested)->greaterThan(now()->subMinutes(self::ForwardMinutes))
            && (! $operation->status->terminal() || $this->outlivesTimeout($operation))
            && ! $this->holdsForCopy($operation);
    }

    /**
     * True while a live run's database copy step runs, within its bound and margin: the cancel waits for the step's end, and the
     * progress of that end asks the reconciler to forward it at once. A timed-out run is not held: nothing would end it.
     */
    public function holdsForCopy(Operation $operation): bool
    {
        return array_any($this->copySteps($operation), static fn (array $row): bool => $row['within']);
    }

    /** Logs a copy step that still shows running past its bound, once, where the cancel is sent anyway. */
    public function logBoundPassed(Operation $operation): void
    {
        foreach ($this->copySteps($operation) as $row) {
            if (! $row['within']) {
                Log::warning('operation.cancel_copy_bound_passed', ['operation_id' => $operation->id, 'step' => $row['step'], 'since' => $row['since']]);
            }
        }
    }

    /**
     * The copy steps that the current run's progress shows running, each with its start and whether it is within its bound and margin.
     * The start is the earlier of Swamp's `started_at` and the Gateway's `observed_at`, so Swamp's clock cannot stretch the bound.
     * Progress of an earlier run of the Operation holds nothing.
     *
     * @return list<array{step: string, since: ?string, within: bool}>
     */
    private function copySteps(Operation $operation): array
    {
        $steps = self::CopySteps[$operation->kind] ?? [];
        $progress = $operation->state['native_progress'] ?? null;
        if ($steps === [] || $operation->status->terminal() || ! is_array($progress) || ! is_array($progress['active'] ?? null)
            || ($progress['run'] ?? null) !== ($operation->state['swamp_run_id'] ?? null)) {
            return [];
        }
        $rows = [];
        foreach ($progress['active'] as $row) {
            $step = is_array($row) ? ($row['step'] ?? null) : null;
            if (! is_string($step) || ! isset($steps[$step])) {
                continue;
            }
            $times = array_map(Carbon::parse(...), array_values(array_filter([$row['started_at'] ?? null, $row['observed_at'] ?? null], is_string(...))));
            $since = $times === [] ? null : min($times);
            $rows[] = ['step' => $step, 'since' => $since?->toISOString(),
                'within' => $since !== null && $since->greaterThan(now()->subSeconds($steps[$step] + self::CopyMarginSeconds))];
        }

        return $rows;
    }

    /**
     * True when the step event ends one of the kind's database copy steps.
     *
     * @param  array<string, mixed>  $event
     */
    public static function endsCopy(Operation $operation, array $event): bool
    {
        $step = $event['stepId'] ?? null;

        return in_array($event['kind'] ?? null, ['step_completed', 'step_failed'], true)
            && is_string($step) && isset((self::CopySteps[$operation->kind] ?? [])[$step]);
    }

    /** True for a failed Operation that the Gateway timed out and whose native run has no recorded end. */
    public function outlivesTimeout(Operation $operation): bool
    {
        return ExpireOperationAction::expiredUnsettled($operation) && isset($operation->state['swamp_run_id']);
    }
}
