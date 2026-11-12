<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\FailNativeOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
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
use App\Infrastructure\Swamp\ReadNativeRunFailure;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /** Forwarding cancels stops after this many seconds, so a slow Swamp cannot starve the reads of the pass. */
    private const int CancelSeconds = 150;

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
        $started = microtime(true);
        $cancellation = app(NativeRunCancellation::class);
        OperationRunBudgets::duringTick(function () use ($kinds, $step, $expire, $cancel, $store, $cancellation, $started): void {
            $forward = [];
            foreach (self::open($kinds)->lazyById() as $operation) {
                try {
                    if (InstanceMigrateProjection::unsettled($operation)) {
                        app(InstanceMigrateProjection::class)->rememberSettlementLimit($operation);
                    }
                    $operation = $this->reconcile($operation, $step, $expire);
                    if ($cancellation->awaitsForwarding($operation)) {
                        $forward[] = $operation;
                    }
                } catch (Throwable $exception) {
                    $this->warn($operation, $exception);
                } finally {
                    if ($operation->kind === 'instance.migrate' && InstanceMigrateProjection::unsettled($operation->refresh())) {
                        app(InstanceMigrateProjection::class)->rememberSettlementLimit($operation);
                        app(InstanceMigrateProjection::class)->endUnavailable($operation);
                    }
                }
            }
            // Reads first: a cancel can wait for Swamp's reply for half a minute.
            foreach ($forward as $operation) {
                if (microtime(true) - $started > self::CancelSeconds) {
                    break;
                }
                try {
                    $this->forwardCancel($operation, $cancel, $store);
                } catch (Throwable $exception) {
                    $this->warn($operation, $exception);
                }
            }
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

    /** Remote text can contain inputs: log identity and type only. */
    private function warn(Operation $operation, Throwable $exception): void
    {
        Log::warning('The Operation could not be reconciled with its Swamp run; the next pass tries again.',
            ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
    }

    /**
     * Unfinished Operations, those the Gateway timed out while Swamp has not proven their run ended, and timed-out ones with a
     * cancel request Swamp has not confirmed.
     *
     * @param  list<string>  $kinds
     * @return Builder<Operation>
     */
    private static function open(array $kinds): Builder
    {
        return Operation::query()->whereIn('kind', $kinds)->where(fn ($query) => $query
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhere(fn ($query) => $query->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')->whereNotNull('lock_key'))
            ->orWhere(fn ($query) => $query->where('kind', 'instance.migrate')->where('status', OperationStatus::Failed)
                ->where('error_code', 'operation.timed_out')->whereNull('state->native_timeout->no_run_verified_at')
                ->where(fn ($query) => $query->whereNull('state->native_timeout->native_terminal_status')
                    ->orWhere('state->native_timeout->native_terminal_status', 'unproven')))
            ->orWhere(fn ($query) => $query->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')
                ->where('state->native_cancel_requested_at', '>', now()->subMinutes(NativeRunCancellation::ForwardMinutes)->toISOString())
                ->whereNull('state->native_cancel_confirmed_at')->whereNull('state->native_timeout->native_terminal_status')));
    }

    private function reconcile(Operation $operation, SwampCommandStep $step, ExpireOperationAction $expire): Operation
    {
        if ($operation->status === OperationStatus::Waiting && isset($operation->input['capacity_request'])) {
            app(TaskVmAdmission::class)->admit($operation->id);

            return $operation->refresh();
        }
        $direct = app(DirectOperation::class);
        $selector = $direct->definition($operation->kind)['terminal_result'] ?? null;
        if ($selector === null) {
            // Fleet's two undeclared terminal results keep their existing generic failure, now owned by this job.
            $operation = $expire->execute($operation);
            $run = $operation->state['swamp_run_id'] ?? null;
            if (! $operation->status->terminal() && $operation->node_id !== null && is_string($run) && Str::isUuid($run)) {
                $definition = $direct->definition($operation->kind) ?? throw new \LogicException('The reconciler requires a registered kind.');
                $receipt = app(ReadNativeRunFailure::class)->read($operation, $definition['workflow'], $run);
                if ($receipt !== null) {
                    return app(FailNativeOperationAction::class)->execute($operation, $run, $receipt['status'], $receipt['failure'], $receipt['finished_at']);
                }
            }

            return $operation;
        }
        if (! $direct->usesNativeTerminal($operation)) {
            return $expire->execute($operation);
        }
        if (! isset($operation->state['swamp_run_id'])) {
            $operation = $expire->execute($operation)->refresh();
            if ($operation->error_code === 'swamp.start_uncertain' || ExpireOperationAction::awaitsSettlement($operation)) {
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
