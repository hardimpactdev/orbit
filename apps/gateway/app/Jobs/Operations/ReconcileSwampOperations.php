<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RetiredOperationSettlement;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Tasks\TaskVmAdmission;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRequestDeadline;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that settles an Operation with its Swamp run. Every pass reads each open Operation against Swamp and projects
 * the ones whose run ended. It also expires an Operation at its budget, finds a run whose start confirmation was lost,
 * forwards a recorded cancel, and frees the target of a run that nothing proves ended. A failed read changes nothing; the
 * next pass reads again. The scheduler runs it every minute, and the event observer asks for a pass when a run ends.
 */
final class ReconcileSwampOperations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    private const int PassSeconds = 180;

    private const int RecoverySeconds = 30;

    private const int CurrentSeconds = 90;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step, ExpireOperationAction $expire, CancelSwampRun $cancel, OperationStore $store): void
    {
        $kinds = array_keys(app(DirectOperation::class)->definitions());
        if ($kinds === []) {
            return;
        }
        $deadline = now()->addSeconds(self::PassSeconds);
        $cancellation = app(NativeRunCancellation::class);
        OperationRunBudgets::duringTick(function () use ($kinds, $step, $expire, $cancel, $store, $cancellation, $deadline): void {
            // Current work and cancel forwarding get their turn before any lost-run recovery.
            $seen = [];
            $current = self::open($kinds)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting]);
            $this->checkBatch($current, now()->addSeconds(self::CurrentSeconds), $step, $expire, $cancel, $store, $cancellation, $seen);
            $pending = self::open($kinds)->where('status', OperationStatus::Failed)
                ->whereNotNull('state->native_cancel_requested_at')->whereNull('state->native_cancel_confirmed_at')
                ->where('state->native_cancel_requested_at', '>', now()->subMinutes(NativeRunCancellation::ForwardMinutes)->toISOString());
            $this->checkBatch($pending, $deadline, $step, $expire, $cancel, $store, $cancellation, $seen);
            $recovery = self::open($kinds)->where('status', OperationStatus::Failed)
                ->where(fn ($query) => $query->whereNull('state->native_reconcile->next_at')
                    ->orWhere('state->native_reconcile->next_at', '<=', now()->toISOString()));
            $recoveryDeadline = now()->addSeconds(self::RecoverySeconds)->min($deadline);
            $this->checkBatch($recovery, $recoveryDeadline, $step, $expire, $cancel, $store, $cancellation, $seen);
        });
        $expire->releaseStaleHolds();
        // An Operation of a kind that left the registry is read by nothing above; it ends on a cancel request or at its budget.
        try {
            app(RetiredOperationSettlement::class)->sweep();
        } catch (Throwable $exception) {
            Log::warning('The Operations of retired kinds could not be settled; the next pass tries again.', ['exception_type' => $exception::class]);
        }
        // A proof.run has no Swamp run, so no budget of its own: this ends one whose request stopped, and closes one whose runs have ended.
        try {
            app(ProofRunWatch::class)->sweep();
        } catch (Throwable $exception) {
            Log::warning('The proof runs could not be watched; the next pass tries again.', ['exception_type' => $exception::class]);
        }
        app(RoutePublicationHolds::class)->sweep();
    }

    /** @param Builder<Operation> $query
     * @param  list<int>  $seen
     */
    private function checkBatch(Builder $query, Carbon $deadline, SwampCommandStep $step, ExpireOperationAction $expire,
        CancelSwampRun $cancel, OperationStore $store, NativeRunCancellation $cancellation, array &$seen): void
    {
        foreach ($query->whereNotIn('id', $seen)->orderByRaw('cancel_requested_at IS NULL')->orderBy('state->native_reconcile->checked_at')->orderBy('id')->limit(100)->get() as $operation) {
            if (! $deadline->isFuture()) {
                break;
            }
            $seen[] = $operation->id;
            // Persist rotation before the remote call, including when a worker stops during it.
            DB::transaction(function () use ($operation): void {
                $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
                $previous = $current->state['native_reconcile'] ?? [];
                $attempt = is_array($previous) && is_int($previous['attempt'] ?? null) ? $previous['attempt'] : 0;
                $attempt = ExpireOperationAction::expiredUnsettled($current) ? min(7, $attempt + 1) : 0;
                $current->update(['state' => [...($current->state ?? []), 'native_reconcile' => [
                    'checked_at' => now()->toISOString(), 'attempt' => $attempt,
                    'next_at' => $attempt === 0 ? null : now()->addSeconds(min(3600, 60 * (2 ** ($attempt - 1))))->toISOString(),
                ]]]);
            });
            try {
                $operation = SwampRequestDeadline::during($deadline, fn (): Operation => $this->reconcile($operation->refresh(), $step, $expire));
            } catch (Throwable $exception) {
                $this->warn($operation, $exception);
            }
            try {
                if ($cancellation->awaitsForwarding($operation->refresh()) && now()->lessThan($deadline)) {
                    SwampRequestDeadline::during($deadline, fn () => $this->forwardCancel($operation, $cancel, $store));
                }
            } catch (Throwable $exception) {
                $this->warn($operation, $exception);
            }
        }
    }

    /** Remote text can contain inputs: log identity and type only. */
    private function warn(Operation $operation, Throwable $exception): void
    {
        Log::warning('The Operation could not be reconciled with its Swamp run; the next pass tries again.',
            ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
    }

    /**
     * Unfinished Operations and every provisional expiry until native terminal projection or verified absence of a run.
     * Cancellation confirmation and target locks do not prove that native restore and finish ended.
     *
     * @param  list<string>  $kinds
     * @return Builder<Operation>
     */
    private static function open(array $kinds): Builder
    {
        return Operation::query()->whereIn('kind', $kinds)->where(fn ($query) => $query
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhere(fn ($query) => $query->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')
                ->whereNull('state->native_timeout->no_run_verified_at')
                ->where(fn ($query) => $query->whereNull('state->native_timeout->native_terminal_status')
                    ->orWhere('state->native_timeout->native_terminal_status', 'unproven'))));
    }

    private function reconcile(Operation $operation, SwampCommandStep $step, ExpireOperationAction $expire): Operation
    {
        if ($operation->status === OperationStatus::Waiting && isset($operation->input['capacity_request'])) {
            app(TaskVmAdmission::class)->admit($operation->id);

            return $operation->refresh();
        }
        $direct = app(DirectOperation::class);
        $selector = $direct->definition($operation->kind)['terminal_result'] ?? null;
        if ($selector === null || ! $direct->usesNativeTerminal($operation)) {
            return $expire->execute($operation);
        }
        if (! isset($operation->state['swamp_run_id'])) {
            $operation = $expire->execute($operation)->refresh();
            if ($operation->error_code === 'swamp.start_uncertain' || ExpireOperationAction::expiredUnsettled($operation)) {
                $step->recoverUnbound($operation);
                $operation->refresh();
            }
        }
        $run = $operation->state['swamp_run_id'] ?? null;
        if (is_string($run)) {
            if ($operation->cancel_requested_at !== null) {
                app(NativeRunCancellation::class)->request($operation);
            }
            $this->project($operation, $run, $selector, $step, $expire);
            $operation->refresh();
        }

        return $operation;
    }

    /**
     * @param  array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>,native?:true,data?:string}  $selector
     */
    private function project(Operation $operation, string $run, array $selector, SwampCommandStep $step, ExpireOperationAction $expire): void
    {
        $deadline = OperationRunBudgets::deadline($operation);
        $atDeadline = $deadline !== null && ! $deadline->isFuture();
        try {
            $step->projectTerminal($operation, ['id' => $run], $selector, $atDeadline);
        } catch (SwampTerminalReadUnavailable) {
            // The run has not ended in the history yet, or serve did not answer: the next pass reads again.
            $this->expireAtDeadline($operation, $atDeadline, $expire);
        } catch (Throwable $exception) {
            $this->expireAtDeadline($operation, $atDeadline, $expire);
            throw $exception;
        }
    }

    /** A run that cannot be read at its budget must not hold its Operation open. */
    private function expireAtDeadline(Operation $operation, bool $atDeadline, ExpireOperationAction $expire): void
    {
        if ($atDeadline) {
            $expire->execute($operation->refresh());
        }
    }

    /** Asks Swamp to cancel the run and records the confirmation, unless the request was recorded again while Swamp answered. */
    private function forwardCancel(Operation $operation, CancelSwampRun $cancel, OperationStore $store): void
    {
        $requested = $operation->state['native_cancel_requested_at'] ?? null;
        $cancel->execute($operation);
        DB::transaction(function () use ($operation, $store, $requested): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $state = $current->state ?? [];
            if (($state['native_cancel_requested_at'] ?? null) === $requested) {
                $store->change($current, ['state' => [...$state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
            }
        });
    }
}
