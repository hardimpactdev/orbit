<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\InstanceDestroyEngineFlow;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Processes\ProcessControlEngineFlow;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Schedules\ScheduleEnableEngineFlow;
use App\Domain\Tasks\TaskSyncEngineFlow;
use App\Infrastructure\Executor\ExecutorJournalLookup;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The Gateway state machine (ADR 0271; docs/reference/gateway-engine.md). An Operation is a set of steps. Nothing stays open
 * while a Node works: `advance` sends what can run, and every receipt, start event and deadline records what happened and
 * calls `advance` again. Each transition is one database transaction under the Operation's row lock; orders are published
 * after it commits. A re-sent step is safe: the executor answers a finished one with its stored result as a repeat.
 */
final readonly class Engine
{
    /** How long past its re-send time a sent step waits before the reconciler nudges it: its own deadline comes first. */
    private const int NudgeGraceMs = 30_000;

    /** How often an ended run's projection is tried before the Operation fails, and the first retry's delay, doubled each time. */
    private const int FinishAttempts = 3;

    private const int FinishBackoffMs = 2_000;

    /** The code and step of an Operation whose projection kept throwing. */
    public const string ProjectionFailed = 'engine.projection_failed';

    public const string ProjectionStep = 'projection';

    /**
     * Kinds whose target stays fenced until each sent step's terminal receipt: no budget fails a sent step, no expiry ends the
     * Operation, and a lost receipt is sent again under the same step ID, so the executor's journal repeats the stored result.
     */
    private const array ReceiptBound = [InstanceDestroyEngineFlow::Kind, TaskSyncEngineFlow::Kind, ...ProcessControlEngineFlow::Kinds, ScheduleEnableEngineFlow::Kind, 'route.destroy',
        RouteUpdateEngineFlow::Kind];

    /** The one successful receipt status each fenced action answers; any other success status is not its result. */
    private const array FencedSuccess = ['instance.destroy' => 'done', 'dns.publish_records' => 'applied', 'route.withdraw_site' => 'applied', 'instance.fetch_branch' => 'output', 'instance.sync_base' => 'output',
        'instance.clear_base' => 'output', 'tls.ensure_csr' => 'output', 'route.publish_site' => 'applied', ProcessControlEngineFlow::Action => 'applied', ProcessControlEngineFlow::ReadAction => 'output', ProcessControlEngineFlow::RestartAction => 'output', ScheduleEnableEngineFlow::Action => 'output'];

    public function __construct(private MachineBus $bus, private EngineKinds $kinds, private OperationStore $operations) {}

    /**
     * Records the Operation's steps and publishes its start event. A receipt-bound run binds with the steps and its reconciler
     * retries an uncertain publish; other runs throw to the caller on a failed start.
     */
    public function start(Operation $operation, ?Closure $bind = null): void
    {
        $definition = $this->kinds->definition($operation->kind);
        $specs = $definition->steps($operation);
        DB::transaction(function () use ($operation, $specs, $bind): void {
            $bind?->__invoke();
            foreach ($specs as $spec) {
                OperationEngineStep::query()->firstOrCreate(['operation_id' => $operation->id, 'name' => $spec->name], [
                    'node_id' => $spec->node, 'action' => $spec->action, 'target' => $spec->target, 'after' => $spec->after,
                    'budget_ms' => $spec->budgetMs, 'resend_ms' => $spec->resendMs, 'on_failure' => $spec->onFailure, 'optional' => $spec->optional,
                ]);
            }
            $state = $operation->refresh()->state ?? [];
            $state['engine'] = ['started_ms' => self::nowMs(), 'finished_ms' => null];
            $this->operations->change($operation, ['state' => $state]);
        });
        try {
            $this->bus->publishStart($operation->id);
        } catch (Throwable $exception) {
            if (! self::requiresReceipt($operation)) {
                throw $exception;
            }
            report($exception);
        }
    }

    /**
     * A start event or a deadline from the `ENGINE` stream.
     *
     * @param  array<string, mixed>  $event
     */
    public function onEvent(string $subject, array $event): void
    {
        $operation = $event['operation'] ?? null;
        if (is_int($operation) && $operation > 0) {
            $this->advance($operation, $subject === 'orbit.engine.deadline');
        }
    }

    /**
     * A receipt from a Node's executor: `{node, line}`, the line's `operation` being `<id>:<step>`.
     *
     * @param  array<string, mixed>  $receipt
     */
    public function onReceipt(array $receipt): void
    {
        $line = ValidatedData::object($receipt['line'] ?? []);
        $node = is_string($receipt['node'] ?? null) && ctype_digit($receipt['node']) ? (int) $receipt['node'] : ($receipt['node'] ?? 0);
        if (! is_int($node) || preg_match('/\A(\d+):([A-Za-z0-9._-]{1,100})\z/D', self::text($line['operation'] ?? null), $match) !== 1) {
            return; // Not an engine order's receipt.
        }
        $operationId = (int) $match[1];
        DB::transaction(function () use ($operationId, $match, $node, $line): void {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || ! self::owns($operation)) {
                return;
            }
            $step = OperationEngineStep::query()->where('operation_id', $operationId)->where('name', $match[2])->lockForUpdate()->first();
            if ($step === null || $step->node_id !== $node) {
                return; // A receipt from another Node is not this step's.
            }
            self::record($step, $line, self::requiresReceipt($operation) ? $operation : null);
        });
        $this->advance($operationId);
    }

    /**
     * Stops sending steps: the next transition sees `cancel_requested_at` and ends the run. A bus that is down only delays that
     * transition to the next receipt, deadline or nudge; the cancel itself is recorded already.
     */
    public function cancel(Operation $operation): void
    {
        try {
            $this->bus->nudge($operation->id, 'cancel-'.self::nowMs());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The reconciler's recovery for a start or an order lost between a commit and its publish: an unfinished Operation with a
     * pending step, or a sent step well past its re-send time, gets one deadline event per minute.
     */
    public function nudgeIfStalled(Operation $operation): void
    {
        $now = self::nowMs();
        $stalled = OperationEngineStep::query()->where('operation_id', $operation->id)
            ->where(static fn ($query) => $query->where('status', 'pending')
                ->when(self::requiresReceipt($operation), static fn ($query) => $query->orWhereIn('status', ['done', 'failed']))
                ->orWhere(static fn ($sent) => $sent->where('status', 'sent')->where('resend_at_ms', '<', $now - self::NudgeGraceMs)))
            ->exists();
        if (! $stalled) {
            return;
        }
        try {
            $this->bus->nudge($operation->id, 'nudge-'.intdiv($now, 60_000));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function owns(Operation $operation): bool
    {
        return ($operation->state['swamp_run_id'] ?? null) === EngineRun::runIdOf($operation->id);
    }

    public static function receiptBound(string $kind): bool
    {
        return in_array($kind, self::ReceiptBound, true);
    }

    /**
     * The Operation lock a receipt-bound kind holds until its end: the resource its orders change. A Schedule's enable locks its UUID; a task copy's sync locks its
     * copy, so no removal or other writer of that Instance starts beside it; the group stays fenced by its running Operation.
     */
    public static function fenceKey(Operation $operation): string
    {
        return match ($operation->kind) {
            TaskSyncEngineFlow::Kind => OperationStore::lockKey('instance', ValidatedData::integer(ValidatedData::object($operation->input)['instance_id'] ?? 0)),
            // A Schedule's UUID, so the owner's other Schedules stay free (EngineTargetFence).
            ScheduleEnableEngineFlow::Kind => EngineTargetFence::scheduleKey(ValidatedData::string(ValidatedData::object($operation->input)['id'] ?? null)),
            default => OperationStore::lockKey($operation->target_type, $operation->target_id),
        };
    }

    public static function requiresReceipt(Operation $operation): bool
    {
        return self::owns($operation) && self::receiptBound($operation->kind);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function nowMs(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }

    /**
     * Applies one receipt to its step. A receipt for a finished step only counts as late.
     *
     * @param  array<string, mixed>  $line
     */
    private static function record(OperationEngineStep $step, array $line, ?Operation $receiptBound = null): void
    {
        if ($receiptBound !== null && ($step->first_order_expires_ms === null || ($line['operation'] ?? null) !== $receiptBound->id.':'.$step->name)) {
            return;
        }
        if (in_array($step->status, ['done', 'failed'], true)) {
            $step->update(['late' => $step->late + 1]);

            return;
        }
        $status = self::text($line['status'] ?? null);
        $reason = self::text($line['reason'] ?? null);
        // A redelivered order's replay carries the first order's result once it exists: that result is the step's.
        if ($status === 'refused' && $reason === 'replayed_nonce' && is_array($line['first'] ?? null)) {
            self::record($step, ValidatedData::object($line['first']), $receiptBound);

            return;
        }
        if ($receiptBound !== null) {
            $success = self::FencedSuccess[$step->action] ?? 'done';
            // An expired order and content the executor could not read never ran: only a terminal result settles the step.
            if (! in_array($status, [$success, 'failed', ...($success === 'done' ? [] : ['done', 'applied', 'output'])], true)
                || ($line['action'] ?? null) !== $step->action || ($line['target'] ?? null) !== $step->target
                || ($line['code'] ?? null) === 'executor.order_expired' || str_starts_with(self::text($line['code'] ?? null), 'blob.')) {
                return;
            }
            if ($status !== 'failed' && ($status !== $success || ($success === 'output' && ! self::evidence($receiptBound, $step, $line)))) {
                $step->update(['status' => 'failed', 'done_ms' => self::nowMs(), 'error_code' => 'engine.receipt_invalid']);

                return;
            }
        }
        match (true) {
            in_array($status, ['done', 'applied', 'output'], true) => $step->update(['status' => 'done', 'done_ms' => self::nowMs(),
                'output' => match ($status) {
                    'applied' => (string) (is_int($line['changes'] ?? null) ? $line['changes'] : 0),
                    'output' => is_string($line['stdout'] ?? null) ? $line['stdout'] : null,
                    default => null,
                }]),
            // The first order's result comes, or came, with its own receipt.
            $status === 'refused' && $reason === 'replayed_nonce' => null,
            // The order waited longer than its expiry: the next transition sends a new one.
            // An order that expired before the executor took its lock never acted either, so it is sent again too.
            $status === 'refused' && $reason === 'expired',
            $status === 'failed' && ($line['code'] ?? null) === 'executor.order_expired' => $step->update(['status' => 'pending']),
            in_array($status, ['failed', 'partial', 'refused'], true) => $step->update(['status' => 'failed', 'done_ms' => self::nowMs(),
                'error_code' => mb_strcut(self::text($line['code'] ?? null) ?: ($reason ?: 'executor.failed'), 0, 128),
                ...($receiptBound !== null && is_string($line['detail'] ?? null) && $line['detail'] !== ''
                    ? ['error_detail' => app(NativeStepFailure::class)->written($step->name, $line['detail'], $receiptBound)['detail']] : [])]),
            default => null,
        };
    }

    /**
     * Whether a fenced step's output is the evidence its kind defines; a kind without a rule takes any text.
     *
     * @param  array<string, mixed>  $line
     */
    private static function evidence(Operation $operation, OperationEngineStep $step, array $line): bool
    {
        $definition = app(EngineKinds::class)->definition($operation->kind);

        return ! $definition instanceof EngineEvidence || $definition->evidence($operation, $step->name, is_string($line['stdout'] ?? null) ? $line['stdout'] : '');
    }

    /**
     * Applies every possible transition of one Operation, then sends its orders, runs its Gateway steps and, at its end, its
     * projection.
     */
    private function advance(int $operationId, bool $deadline = false): void
    {
        $sends = [];
        $locals = [];
        $waits = [];
        $end = null;
        $operation = DB::transaction(function () use ($operationId, $deadline, &$sends, &$locals, &$waits, &$end): ?Operation {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || ! self::owns($operation) || $operation->status->terminal()) {
                return null;
            }
            // The run ended but its projection did not record it: the event came back, so record it again.
            if ((ValidatedData::object($operation->state['engine'] ?? [])['finished_ms'] ?? null) !== null) {
                $end = 'recorded';

                return $operation;
            }
            $now = self::nowMs();
            $steps = self::steps($operationId, lock: true);
            $definition = $this->kinds->definition($operation->kind);
            // A waiting Gateway step's time, from the steps as recorded now: null when it may run at once.
            $until = static fn (OperationEngineStep $step): ?int => $step->node_id === 0 && $definition instanceof EngineWait
                ? $definition->waitUntilMs($operation, $step->name, new EngineRun($operationId, $steps, 0, null)) : null;
            $done = static fn (string $dep): bool => ($steps[$dep] ?? null)?->status === 'done';
            $receiptBound = self::requiresReceipt($operation);
            $cancel = $operation->cancel_requested_at !== null;
            // A receipt-bound cancel or failure sends no new step: it waits for each published order's terminal receipt, then runs
            // the failure steps for the work that was done, and ends. A step whose first order was never published did nothing.
            $published = static fn (OperationEngineStep $step): bool => $step->first_order_expires_ms !== null;
            $outstanding = array_any($steps, static fn (OperationEngineStep $step): bool => $step->status === 'sent' && $published($step));
            $acted = array_any($steps, $published);
            foreach ($steps as $step) {
                if (! $receiptBound && $step->status === 'sent' && $step->first_sent_ms !== null && $now - $step->first_sent_ms > $step->budget_ms) {
                    $step->update(['status' => 'failed', 'done_ms' => $now, 'error_code' => 'engine.step_budget_exceeded']);
                }
            }
            $main = array_filter($steps, static fn (OperationEngineStep $step): bool => ! $step->on_failure && ! $step->optional);
            $failed = array_any($main, static fn (OperationEngineStep $step): bool => $step->status === 'failed');
            $pending = array_any($main, static fn (OperationEngineStep $step): bool => $step->status === 'sent' && $published($step));
            $rescue = $receiptBound ? ($failed || ($cancel && $acted)) && ! $pending : $failed;
            foreach ($steps as $step) {
                if ($receiptBound && ($cancel || $failed) && ! $step->on_failure) {
                    if ($step->status !== 'sent' || ! $published($step)) {
                        continue; // Only a published order is resent until its terminal receipt.
                    }
                } elseif ($step->on_failure !== $rescue && ! ($receiptBound && ! $step->on_failure && $step->status === 'sent' && $published($step))) {
                    // A failed receipt-bound run still resends each published order until its terminal receipt.
                    continue; // A failed run sends no more of its own steps; a run without a failure none of its failure steps.
                }
                if ($step->on_failure && $step->status === 'pending' && ! array_all($step->after, $done)) {
                    $step->update(['status' => 'skipped', 'done_ms' => $now]);

                    continue;
                }
                $ready = $step->status === 'pending' && array_all($step->after, $done);
                // A receipt-bound run's Gateway step whose worker was lost after it was marked sent runs again: each is idempotent.
                $overdue = $deadline && $step->status === 'sent' && $now >= ($step->resend_at_ms ?? PHP_INT_MAX)
                    && ($step->node_id > 0 || $receiptBound || $until($step) !== null);
                if (! $ready && ! $overdue) {
                    continue;
                }
                // A wait keeps its time on the step and its deadline on the bus: nothing holds a process, so a restart resumes
                // what is left, and a cancel ends the run before the step runs.
                $wait = $until($step);
                if ($wait !== null && $wait > $now) {
                    $step->update(['status' => 'sent', 'sends' => $step->sends + 1, 'first_sent_ms' => $step->first_sent_ms ?? $now, 'resend_at_ms' => $wait]);
                    $waits[$step->name] = [$step->sends, $wait];

                    continue;
                }
                if ($receiptBound && ! self::withinReplayWindow($step, $now + ExecutorOrderSigner::StepExpiresAfterMs)) {
                    $step->update(['resend_at_ms' => null]);

                    continue;
                }
                $step->update(['status' => 'sent', 'sends' => $step->sends + 1, 'first_sent_ms' => $step->first_sent_ms ?? $now,
                    'resend_at_ms' => $now + $step->resend_ms]);
                $step->node_id === 0 ? $locals[] = $step->name : $sends[$step->name] = $step->sends;
            }
            $rescued = array_all(array_filter($steps, static fn (OperationEngineStep $step): bool => $step->on_failure),
                static fn (OperationEngineStep $step): bool => in_array($step->status, ['done', 'failed', 'skipped'], true));
            // What happened on the Nodes comes first: a cancel that came after every step ended does not hide its result. A failed run
            // ends once its failure steps ended.
            $end = match (true) {
                // A receipt-bound run that failed keeps its fence until each published order has its terminal receipt.
                $failed => $rescued && ! ($receiptBound && $outstanding) ? 'failed' : null,
                array_all($main, static fn (OperationEngineStep $step): bool => $step->status === 'done') => 'succeeded',
                $receiptBound && $cancel => ! $outstanding && (! $acted || $rescued) ? 'cancelled' : null,
                $cancel => 'cancelled',
                default => null,
            };
            $state = $operation->state ?? [];
            if ($end !== null) {
                $state['engine'] = [...ValidatedData::object($state['engine'] ?? []), 'finished_ms' => $now];
                $sends = $locals = $waits = [];
            }
            $state['native_progress'] = self::progress($operationId, $steps);
            // A started wait is the current step until it ends; an early event keeps it.
            $this->operations->change($operation, ['state' => $state, 'current_step' => $end === null ? (array_key_last($sends) ?? (end($locals) ?: (array_key_last($waits) ?? $operation->current_step))) : null]);

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        $definition = $this->kinds->definition($operation->kind);
        foreach ($waits as $name => [$attempt, $atMs]) {
            try {
                $this->bus->scheduleDeadline($operationId, $name, $attempt, $atMs);
            } catch (Throwable $exception) {
                // The step keeps its time: the reconciler's nudge runs it once that time has passed.
                report($exception);
            }
        }
        if ($this->send($operation, $definition, $sends)) {
            $this->advance($operationId);

            return;
        }
        foreach ($locals as $name) {
            $this->runLocal($operation, $definition, $name);
        }
        if ($end !== null) {
            $this->finish($operation, $definition);
        }
    }

    /**
     * Runs the kind's projection of the ended run. A projection that throws is tried again after a growing backoff, by a
     * deadline rather than the message's redelivery, and after FinishAttempts the Operation fails with
     * `engine.projection_failed`: its lock goes, nothing is redelivered and nothing more is logged.
     */
    private function finish(Operation $operation, EngineDefinition $definition): void
    {
        $engine = ValidatedData::object($operation->refresh()->state['engine'] ?? []);
        $attempts = ValidatedData::integer($engine['finish_attempts'] ?? 0);
        if (self::nowMs() < ValidatedData::integer($engine['finish_retry_ms'] ?? 0)) {
            return; // A backed-off retry waits for its own deadline.
        }
        try {
            // One transaction: the terminal result commits with every write the projection makes after it, or none does and the
            // end stays retryable.
            DB::transaction(fn () => $definition->finish($operation, $this->run($operation->id)));

            return;
        } catch (Throwable $exception) {
            report($exception);
        }
        $attempts++;
        $state = $operation->refresh()->state ?? [];
        if ($attempts >= self::FinishAttempts) {
            $state['engine'] = [...ValidatedData::object($state['engine'] ?? []), 'finish_attempts' => $attempts, 'finish_retry_ms' => null];
            $this->failExhausted($operation, $state);

            return;
        }
        $at = self::nowMs() + self::FinishBackoffMs * 2 ** ($attempts - 1);
        $state['engine'] = [...ValidatedData::object($state['engine'] ?? []), 'finish_attempts' => $attempts, 'finish_retry_ms' => $at];
        $this->operations->change($operation, ['state' => $state], onlyWhileOpen: true);
        try {
            $this->bus->scheduleDeadline($operation->id, self::ProjectionStep, $attempts, $at);
        } catch (Throwable $exception) {
            // Without its deadline the retry runs at the message's redelivery instead; the attempt still counts.
            $state['engine']['finish_retry_ms'] = null;
            $this->operations->change($operation, ['state' => $state], onlyWhileOpen: true);

            throw $exception;
        }
    }

    /**
     * Ends an Operation whose projection kept throwing. The usual terminal write runs the kind's lifecycle hooks, which may be
     * what threw: when it fails too, the end is written without them, so the Operation still fails, its lock goes and the next
     * delivery finds it ended and is acknowledged.
     *
     * @param  array<string, mixed>  $state
     */
    private function failExhausted(Operation $operation, array $state): void
    {
        $changes = ['status' => OperationStatus::Failed, 'error_code' => self::ProjectionFailed, 'failed_step' => self::ProjectionStep,
            'current_step' => null, 'finished_at' => now(), 'lock_key' => null, 'state' => $state,
            'result' => ['error_code' => self::ProjectionFailed, 'failed_step' => self::ProjectionStep]];
        try {
            $this->operations->change($operation, $changes, onlyWhileOpen: true);

            return;
        } catch (Throwable $exception) {
            report($exception);
        }
        DB::transaction(static function () use ($operation, $changes): void {
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! $current->status->terminal()) {
                $current->forceFill([...$changes, 'sequence' => $current->sequence + 1])->save();
            }
        });
        if (RoutePublicationHolds::tracks($operation->kind)) {
            app(RoutePublicationHolds::class)->release($operation->id, false);
        }
        try {
            app(OperationBroadcaster::class)->changed($operation->refresh(), true);
        } catch (Throwable $exception) {
            report($exception); // Watchers read the end at their next poll.
        }
    }

    /**
     * Schedules each step's deadline, then publishes its order. A step whose order cannot be made, such as an input the kind
     * cannot build or an order over 4 KB, normally fails at once. Other send failures return that step to pending and throw,
     * so JetStream delivers the event again. A receipt-bound send instead keeps its reserved deadline and lets it or the
     * reconciler drive the next transition. Sending the deadline first covers a crash before order publication.
     *
     * @param  array<string, int>  $attempts
     * @return bool Whether a step failed, so the Operation must advance to its end.
     */
    private function send(Operation $operation, EngineDefinition $definition, array $attempts): bool
    {
        if ($attempts === []) {
            return false;
        }
        $run = $this->run($operation->id);
        if (self::requiresReceipt($operation)) {
            $failed = false;
            foreach ($attempts as $name => $attempt) {
                $step = $run->steps[$name];
                if ($step->status === 'sent' && $step->sends === $attempt) {
                    $failed = $this->sendFencedOrder($operation, $definition, $step, $run) || $failed;
                }
            }

            return $failed;
        }
        $signer = ExecutorOrderSigner::fromConfig();
        $invalid = [];
        $unsent = [];
        foreach (array_keys($attempts) as $name) {
            $step = $run->steps[$name];
            try {
                $input = $definition->input($operation, $name, $run);
            } catch (Throwable $exception) {
                report($exception);
                $invalid[] = $name;

                continue;
            }
            try {
                // Content goes to the Node's own content keys first; the signed order names only its SHA-256.
                $input = EngineContent::upload($this->bus, $step->node_id, $input);
            } catch (Throwable $exception) {
                report($exception);
                $unsent[] = $name;

                continue;
            }
            try {
                $order = $signer->signStep($operation, $step->node_id, $name, $step->action, $step->target, $input);
            } catch (Throwable $exception) {
                report($exception);
                $invalid[] = $name;

                continue;
            }
            try {
                $this->bus->scheduleDeadline($operation->id, $name, $step->sends, (int) $step->resend_at_ms);
                $this->bus->publishOrder($step->node_id, $order);
            } catch (Throwable $exception) {
                report($exception);
                $unsent[] = $name;
            }
        }
        $failed = $invalid !== [] && OperationEngineStep::query()->where('operation_id', $operation->id)->whereIn('name', $invalid)->where('status', 'sent')
            ->update(['status' => 'failed', 'done_ms' => self::nowMs(), 'error_code' => 'engine.order_invalid']) > 0;
        if ($unsent !== []) {
            OperationEngineStep::query()->where('operation_id', $operation->id)->whereIn('name', $unsent)->where('status', 'sent')
                ->update(['status' => 'pending']);
            throw new RuntimeException('engine.publish_failed: '.implode(',', $unsent));
        }

        return $failed;
    }

    /** @return bool Whether the step failed for good: its order cannot be built or signed, and none was ever published. */
    private function sendFencedOrder(Operation $operation, EngineDefinition $definition, OperationEngineStep $step, EngineRun $run): bool
    {
        try {
            $input = $definition->input($operation, $step->name, $run);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failUnpublished($step);
        }
        try {
            $input = EngineContent::upload($this->bus, $step->node_id, $input);
        } catch (Throwable $exception) {
            Log::warning('Could not send the fenced order.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);

            return false;
        }
        try {
            // A signer the Gateway cannot load yet is its configuration, which a later send may find; an order the signer
            // refuses, such as one over 4 KB, is refused again on every send.
            $order = ExecutorOrderSigner::fromConfig()->signStep($operation, $step->node_id, $step->name, $step->action, $step->target, $input);
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return $this->failUnpublished($step);
        } catch (Throwable $exception) {
            Log::warning('Could not send the fenced order.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);

            return false;
        }
        try {
            $this->bus->scheduleDeadline($operation->id, $step->name, $step->sends, (int) $step->resend_at_ms);
            $this->bus->publishOrder($step->node_id, $order, fn (): bool => $this->claimPublication($step, $order->expiresMs));
        } catch (Throwable $exception) {
            Log::warning('Could not send the fenced order.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }

        return false;
    }

    /** A step that no order of ever reached a Node fails as an invalid order; one that was published waits for its receipt. */
    private function failUnpublished(OperationEngineStep $attempt): bool
    {
        return DB::transaction(static fn (): bool => OperationEngineStep::query()->whereKey($attempt->id)->where('status', 'sent')
            ->where('sends', $attempt->sends)->whereNull('first_order_expires_ms')
            ->update(['status' => 'failed', 'done_ms' => self::nowMs(), 'error_code' => 'engine.order_invalid']) > 0);
    }

    /** Commits the earliest possible publication under the same lock as cancellation and receipts. */
    private function claimPublication(OperationEngineStep $attempt, int $expiryMs): bool
    {
        return DB::transaction(function () use ($attempt, $expiryMs): bool {
            $operation = Operation::query()->lockForUpdate()->findOrFail($attempt->operation_id);
            $step = OperationEngineStep::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! self::requiresReceipt($operation) || $operation->status->terminal()
                || (ValidatedData::object($operation->state['engine'] ?? [])['finished_ms'] ?? null) !== null
                || $operation->lock_key !== self::fenceKey($operation)
                || $step->status !== 'sent' || $step->sends !== $attempt->sends || $step->resend_at_ms === null || self::nowMs() >= $expiryMs
                || ($step->first_order_expires_ms === null && $operation->cancel_requested_at !== null && ! $step->on_failure)
                || ! self::withinReplayWindow($step, $expiryMs)) {
                return false;
            }
            if ($step->first_order_expires_ms === null) {
                $step->update(['first_order_expires_ms' => $expiryMs]);
            }

            return true;
        });
    }

    /** The earliest Node acceptance is the first expiry minus its allowed lookahead. A repeat must expire before that result can be pruned. */
    private static function withinReplayWindow(OperationEngineStep $step, int $expiryMs): bool
    {
        return $step->first_order_expires_ms === null
            || $expiryMs <= $step->first_order_expires_ms - ExecutorOrderSigner::MaxExpiryAheadMs + ExecutorJournalLookup::RetentionMs;
    }

    /** Runs one Gateway step outside any transaction, records its result like a receipt, and advances. */
    private function runLocal(Operation $operation, EngineDefinition $definition, string $name): void
    {
        try {
            $line = ['status' => 'output', 'stdout' => $definition->local($operation, $name, $this->run($operation->id))];
        } catch (Throwable $exception) {
            report($exception);
            $line = ['status' => 'failed', 'code' => 'engine.'.$name.'_failed'];
        }
        DB::transaction(static function () use ($operation, $name, $line): void {
            $step = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', $name)->lockForUpdate()->firstOrFail();
            self::record($step, $line);
        });
        $this->advance($operation->id);
    }

    private function run(int $operationId): EngineRun
    {
        $operation = Operation::query()->findOrFail($operationId);
        $engine = ValidatedData::object($operation->state['engine'] ?? []);
        $started = $engine['started_ms'] ?? null;
        $finished = $engine['finished_ms'] ?? null;

        return new EngineRun($operationId, self::steps($operationId), is_int($started) ? $started : self::nowMs(), is_int($finished) ? $finished : null);
    }

    /** @return array<string, OperationEngineStep> */
    private static function steps(int $operationId, bool $lock = false): array
    {
        $query = OperationEngineStep::query()->where('operation_id', $operationId)->orderBy('id');
        $steps = [];
        foreach (($lock ? $query->lockForUpdate() : $query)->get() as $step) {
            $steps[$step->name] = $step;
        }

        return $steps;
    }

    /**
     * The steps in the shape a Swamp run's progress has, so `operation:show`, the CLI, MCP and Conn read them unchanged.
     *
     * @param  array<string, OperationEngineStep>  $steps
     * @return array<string, mixed>
     */
    private static function progress(int $operationId, array $steps): array
    {
        $at = static fn (?int $ms): ?string => $ms === null ? null : Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond');
        $rows = [];
        $active = [];
        foreach ($steps as $step) {
            if ($step->first_sent_ms === null) {
                continue;
            }
            $status = match ($step->status) {
                'done' => 'succeeded', 'failed' => 'failed', default => 'running'
            };
            $rows[$step->name] = ['name' => $step->name, 'job' => 'engine', 'status' => $status,
                'started_at' => $at($step->first_sent_ms), 'finished_at' => $status === 'running' ? null : $at($step->done_ms)];
            if ($status === 'running') {
                $active[$step->name] = ['step' => $step->name, 'started_at' => $at($step->first_sent_ms)];
            }
        }

        return ['run' => EngineRun::runIdOf($operationId), 'seq' => array_sum(array_map(static fn (OperationEngineStep $step): int => $step->sends, $steps)),
            'active' => $active, 'steps' => $rows];
    }
}
