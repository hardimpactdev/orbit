<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\FailNativeOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationReconcileHealth;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RetiredOperationSettlement;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Tasks\TaskVmAdmission;
use App\Infrastructure\Swamp\ReadNativeRunFailure;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampTerminalOutputUnavailable;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place that settles an Operation with its Swamp run. Every pass reads each open Operation against Swamp and projects
 * the ones whose run ended. It also expires an Operation at its budget, finds a run whose start confirmation was lost,
 * forwards a recorded cancel, and frees the target of a run that nothing proves ended. A failed read changes nothing; the
 * next pass reads again. The scheduler runs it every minute, and the event observer asks for a pass when a run ends. A root run's end also queues the prompt projection of its Operation
 * ({@see ProjectEndedNativeRun}), which runs this same settlement for that Operation at once.
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

    public function handle(SwampCommandStep $step, ExpireOperationAction $expire): void
    {
        $started = hrtime(true);
        $health = new OperationReconcileHealth;
        $operations = $this->pass($step, $expire, $health);
        $health->record($started, $operations);
    }

    private function pass(SwampCommandStep $step, ExpireOperationAction $expire, OperationReconcileHealth $health): int
    {
        $kinds = array_keys(app(DirectOperation::class)->definitions());
        if ($kinds === []) {
            return 0;
        }
        $operations = 0;
        $started = microtime(true);
        $cancellation = app(NativeRunCancellation::class);
        OperationRunBudgets::duringTick(function () use ($kinds, $step, $expire, $cancellation, $started, $health, &$operations): void {
            $forward = [];
            foreach (self::open($kinds)->lazyById() as $operation) {
                $operations++;
                $operation = $this->settle($operation, $step, $expire, $health);
                if ($cancellation->awaitsForwarding($operation)) {
                    $forward[] = $operation;
                }
            }
            // Reads first: a cancel can wait for Swamp's reply for half a minute.
            foreach ($forward as $operation) {
                if (microtime(true) - $started > self::CancelSeconds) {
                    break;
                }
                try {
                    $cancellation->forward($operation);
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

        return $operations;
    }

    /**
     * The prompt settlement of one Operation whose run ended ({@see ProjectEndedNativeRun}): the same read and projection as a pass,
     * for this Operation only, then its recorded cancel if one waits. An Operation that a pass would not read is left alone.
     */
    public function settleOne(int $operationId, SwampCommandStep $step, ExpireOperationAction $expire): void
    {
        $kinds = array_keys(app(DirectOperation::class)->definitions());
        $operation = $kinds === [] ? null : self::open($kinds)->whereKey($operationId)->first();
        if (! $operation instanceof Operation) {
            return;
        }
        OperationRunBudgets::duringTick(function () use ($operation, $step, $expire): void {
            $operation = $this->settle($operation, $step, $expire, new OperationReconcileHealth);
            $cancellation = app(NativeRunCancellation::class);
            if ($cancellation->awaitsForwarding($operation)) {
                try {
                    $cancellation->forward($operation);
                } catch (Throwable $exception) {
                    $this->warn($operation, $exception);
                }
            }
        });
    }

    /**
     * One Operation of a pass: reads it against its run and projects its end. A failure is logged and changes nothing; the next
     * pass reads again. Only the row lock and terminal checks of the projection decide that an end is projected once.
     */
    private function settle(Operation $operation, SwampCommandStep $step, ExpireOperationAction $expire, OperationReconcileHealth $health): Operation
    {
        $completedAt = null;
        $settled = true;
        $onTerminal = static function (CarbonImmutable $at) use (&$completedAt): void {
            $completedAt = $at;
        };
        try {
            if (InstanceMigrateProjection::unsettled($operation)) {
                app(InstanceMigrateProjection::class)->rememberSettlementLimit($operation);
            }
            $operation = $this->reconcile($operation, $step, $expire, $onTerminal);
        } catch (Throwable $exception) {
            $settled = false;
            $this->warn($operation, $exception);
        } finally {
            if ($operation->kind === 'instance.migrate' && InstanceMigrateProjection::unsettled($operation->refresh())) {
                app(InstanceMigrateProjection::class)->rememberSettlementLimit($operation);
                app(InstanceMigrateProjection::class)->endUnavailable($operation);
            }
            if ($completedAt !== null) {
                $health->observe($operation, $completedAt, $settled);
            }
        }

        return $operation;
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

    /** @param Closure(CarbonImmutable):void $onTerminal */
    private function reconcile(Operation $operation, SwampCommandStep $step, ExpireOperationAction $expire, Closure $onTerminal): Operation
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
                $receipt = app(ReadNativeRunFailure::class)->read($operation, $definition['workflow'], $run, $onTerminal);
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
            if ($operation->error_code === 'swamp.start_uncertain' || ExpireOperationAction::awaitsSettlement($operation) || InstanceMigrateProjection::unsettled($operation)) {
                $step->recoverUnbound($operation);
                $operation->refresh();
            }
        }
        $run = $operation->state['swamp_run_id'] ?? null;
        if (is_string($run)) {
            if ($operation->cancel_requested_at !== null) {
                app(NativeRunCancellation::class)->request($operation);
            }
            $this->project($operation, $run, $selector, $step, $expire, $onTerminal);
            $operation->refresh();
        }

        return $operation;
    }

    /**
     * @param  Closure(CarbonImmutable):void  $onTerminal
     * @param  array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>,native?:true,data?:string}  $selector
     */
    private function project(Operation $operation, string $run, array $selector, SwampCommandStep $step, ExpireOperationAction $expire, Closure $onTerminal): void
    {
        $deadline = OperationRunBudgets::deadline($operation);
        $atDeadline = $deadline !== null && ! $deadline->isFuture();
        try {
            $step->projectTerminal($operation, ['id' => $run], $selector, $atDeadline, $onTerminal);
        } catch (SwampTerminalOutputUnavailable $exception) {
            app(InstanceMigrateProjection::class)->missingOutput($operation, $exception->run);
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
}
