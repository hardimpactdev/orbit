<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private MachineBus $bus, private EngineKinds $kinds, private OperationStore $operations) {}

    /**
     * Records the Operation's steps and publishes its start event. A start that cannot be published throws, so the caller
     * records a failed start and nothing is half-started.
     */
    public function start(Operation $operation): void
    {
        $definition = $this->kinds->definition($operation->kind);
        $specs = $definition->steps($operation);
        DB::transaction(function () use ($operation, $specs): void {
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
        $this->bus->publishStart($operation->id);
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
            self::record($step, $line);
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
    private static function record(OperationEngineStep $step, array $line): void
    {
        if (in_array($step->status, ['done', 'failed'], true)) {
            $step->update(['late' => $step->late + 1]);

            return;
        }
        $status = self::text($line['status'] ?? null);
        $reason = self::text($line['reason'] ?? null);
        // A redelivered order's replay carries the first order's result once it exists: that result is the step's.
        if ($status === 'refused' && $reason === 'replayed_nonce' && is_array($line['first'] ?? null)) {
            self::record($step, ValidatedData::object($line['first']));

            return;
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
                'error_code' => mb_strcut(self::text($line['code'] ?? null) ?: ($reason ?: 'executor.failed'), 0, 128)]),
            default => null,
        };
    }

    /**
     * Applies every possible transition of one Operation, then sends its orders, runs its Gateway steps and, at its end, its
     * projection.
     */
    private function advance(int $operationId, bool $deadline = false): void
    {
        $sends = [];
        $locals = [];
        $end = null;
        $operation = DB::transaction(function () use ($operationId, $deadline, &$sends, &$locals, &$end): ?Operation {
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
            $done = static fn (string $dep): bool => ($steps[$dep] ?? null)?->status === 'done';
            foreach ($steps as $step) {
                if ($step->status === 'sent' && $step->first_sent_ms !== null && $now - $step->first_sent_ms > $step->budget_ms) {
                    $step->update(['status' => 'failed', 'done_ms' => $now, 'error_code' => 'engine.step_budget_exceeded']);
                }
            }
            $main = array_filter($steps, static fn (OperationEngineStep $step): bool => ! $step->on_failure && ! $step->optional);
            $failed = array_any($main, static fn (OperationEngineStep $step): bool => $step->status === 'failed');
            foreach ($steps as $step) {
                if ($step->on_failure !== $failed) {
                    continue; // A failed run sends no more of its own steps; a run without a failure none of its failure steps.
                }
                if ($step->on_failure && $step->status === 'pending' && ! array_all($step->after, $done)) {
                    $step->update(['status' => 'skipped', 'done_ms' => $now]);

                    continue;
                }
                $ready = $step->status === 'pending' && array_all($step->after, $done);
                $overdue = $deadline && $step->status === 'sent' && $step->node_id > 0 && $now >= ($step->resend_at_ms ?? PHP_INT_MAX);
                if (! $ready && ! $overdue) {
                    continue;
                }
                $step->update(['status' => 'sent', 'sends' => $step->sends + 1, 'first_sent_ms' => $step->first_sent_ms ?? $now,
                    'resend_at_ms' => $now + $step->resend_ms]);
                $step->node_id === 0 ? $locals[] = $step->name : $sends[] = $step->name;
            }
            $rescued = array_all(array_filter($steps, static fn (OperationEngineStep $step): bool => $step->on_failure),
                static fn (OperationEngineStep $step): bool => in_array($step->status, ['done', 'failed', 'skipped'], true));
            // What happened on the Nodes comes first: a cancel that came after every step ended does not hide its result. A failed run
            // ends once its failure steps ended.
            $end = match (true) {
                $failed => $rescued ? 'failed' : null,
                array_all($main, static fn (OperationEngineStep $step): bool => $step->status === 'done') => 'succeeded',
                $operation->cancel_requested_at !== null => 'cancelled',
                default => null,
            };
            $state = $operation->state ?? [];
            if ($end !== null) {
                $state['engine'] = [...ValidatedData::object($state['engine'] ?? []), 'finished_ms' => $now];
                $sends = $locals = [];
            }
            $state['native_progress'] = self::progress($operationId, $steps);
            $this->operations->change($operation, ['state' => $state, 'current_step' => $end === null ? (end($sends) ?: (end($locals) ?: $operation->current_step)) : null]);

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        $definition = $this->kinds->definition($operation->kind);
        if ($this->send($operation, $definition, $sends)) {
            $this->advance($operationId);

            return;
        }
        foreach ($locals as $name) {
            $this->runLocal($operation, $definition, $name);
        }
        if ($end !== null) {
            $definition->finish($operation->refresh(), $this->run($operationId));
        }
    }

    /**
     * Schedules each step's deadline, then publishes its order. A step whose order cannot be made, such as an input the kind
     * cannot build or an order over 4 KB, fails at once: sending it again would fail the same way. A publish that failed puts
     * its step back to pending and throws, so the consumer negatively acknowledges the event and JetStream delivers it again.
     * The deadline goes first, so a crash before the publish still brings the step back.
     *
     * @param  list<string>  $names
     * @return bool Whether a step failed, so the Operation must advance to its end.
     */
    private function send(Operation $operation, EngineDefinition $definition, array $names): bool
    {
        if ($names === []) {
            return false;
        }
        $run = $this->run($operation->id);
        $signer = ExecutorOrderSigner::fromConfig();
        $invalid = [];
        $unsent = [];
        foreach ($names as $name) {
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
        if ($invalid !== []) {
            OperationEngineStep::query()->where('operation_id', $operation->id)->whereIn('name', $invalid)->where('status', 'sent')
                ->update(['status' => 'failed', 'done_ms' => self::nowMs(), 'error_code' => 'engine.order_invalid']);
        }
        if ($unsent !== []) {
            OperationEngineStep::query()->where('operation_id', $operation->id)->whereIn('name', $unsent)->where('status', 'sent')
                ->update(['status' => 'pending']);
            throw new RuntimeException('engine.publish_failed: '.implode(',', $unsent));
        }

        return $invalid !== [];
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
