<?php

declare(strict_types=1);

namespace App\Spike\StateMachine;

use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Models\Operation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPIKE: the Gateway as a state machine. An operation is a set of steps; each step is one typed primitive for one
 * Node's executor (`node` > 0) or a short step on the Gateway itself (`node` 0), with the steps it waits for. Nothing
 * stays open: `start` sends what can run, every receipt (`onReceipt`, called by the listener) records its step and
 * sends what can run next, and `tick` re-sends a step whose deadline passed. A re-sent step is safe: the executor
 * answers a finished one with its stored result as a `repeat`.
 *
 * A definition class (optional) supplies a step's input from earlier results, runs Gateway steps, and hears the end.
 * State lives in the engine Operation's `state` column; each transition is one database transaction.
 */
final class Engine
{
    public const string Kind = 'spike.sm';

    /** @param array<string, array{node: int, action: string, input?: array, after: list<string>, budget_s: int, resend_s?: int}> $steps */
    public static function start(array $steps, array $faults = [], ?string $definition = null, array $context = []): Operation
    {
        $operation = Operation::query()->create([
            'kind' => self::Kind, 'target_type' => 'node', 'target_id' => 0, 'node_id' => null, 'status' => 'running',
            'triggered_by' => 'spike', 'input' => ['steps' => $steps, 'faults' => $faults, 'definition' => $definition, 'context' => $context],
            'state' => ['started_ms' => self::nowMs(), 'steps' => array_map(static fn (): array => ['status' => 'pending', 'sends' => []], $steps)],
        ]);
        self::advance($operation->id);

        return $operation->refresh();
    }

    /** Called by the listener for every reply line that names a `spike.sm` Operation. */
    public static function onReceipt(int $operationId, int $nodeId, array $line): void
    {
        $status = $line['status'] ?? null;
        DB::transaction(static function () use ($operationId, $nodeId, $line, $status): void {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || $operation->kind !== self::Kind) {
                return;
            }
            $state = $operation->state;
            $step = preg_replace('/\.op'.$operationId.'$/', '', (string) ($line['target'] ?? ''));
            $definition = $operation->input['steps'][$step] ?? null;
            if ($definition === null || (int) $definition['node'] !== $nodeId || ! isset($state['steps'][$step])) {
                return; // An `accepted` line names no step; a receipt from the wrong Node is not this step's.
            }
            $faults = $operation->input['faults'] ?? [];
            if (in_array($step, $faults['drop_receipt'] ?? [], true) && Cache::add("spike-sm:dropped:{$operationId}:{$step}", true, 600)) {
                $state['steps'][$step]['dropped'] = ($state['steps'][$step]['dropped'] ?? 0) + 1;
                $operation->forceFill(['state' => $state])->save();

                return; // Fault: lose this receipt once, as a lost WebSocket frame would.
            }
            $state['steps'][$step] = self::record($state['steps'][$step], $status, $line);
            $operation->forceFill(['state' => $state])->save();
        });
        self::advance($operationId);
    }

    /** Re-sends every sent step whose deadline passed; fails a step whose budget ended. Returns how many it re-sent. */
    public static function tick(): int
    {
        $resent = 0;
        foreach (Operation::query()->where('kind', self::Kind)->where('status', 'running')->pluck('id') as $id) {
            $resent += self::advance((int) $id, resendDue: true);
        }

        return $resent;
    }

    private static function record(array $record, ?string $status, array $line): array
    {
        if (in_array($record['status'], ['done', 'failed'], true)) {
            $record['late'] = ($record['late'] ?? 0) + 1;
        } elseif (in_array($status, ['done', 'applied', 'output'], true)) {
            $record['status'] = 'done';
            $record['done_ms'] = self::nowMs();
            $record['repeat'] = (bool) ($line['repeat'] ?? false);
            if (isset($line['stdout'])) {
                $record['output'] = $line['stdout'];
            }
        } elseif ($status === 'failed' || ($status === 'refused' && ($line['reason'] ?? '') !== 'replayed_nonce')) {
            $record['status'] = 'failed';
            $record['error'] = $line['code'] ?? $line['reason'] ?? 'executor.failed';
        }

        return $record;
    }

    /** Applies every possible transition of one Operation, then sends its instructions. Returns how many it re-sent. */
    private static function advance(int $operationId, bool $resendDue = false): int
    {
        $sends = [];
        $locals = [];
        $resent = 0;
        $finished = null;
        $snapshot = null;
        DB::transaction(static function () use ($operationId, $resendDue, &$sends, &$locals, &$resent, &$finished, &$snapshot): void {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || $operation->status->value !== 'running') {
                return;
            }
            $now = self::nowMs();
            $state = $operation->state;
            foreach ($operation->input['steps'] as $name => $step) {
                $record = $state['steps'][$name];
                $ready = $record['status'] === 'pending'
                    && array_all($step['after'], static fn (string $dep): bool => $state['steps'][$dep]['status'] === 'done');
                $overdue = $resendDue && $record['status'] === 'sent' && (int) $step['node'] > 0 && $now >= $record['resend_ms'];
                if ($record['status'] === 'sent' && $now - $record['first_sent_ms'] > $step['budget_s'] * 1000) {
                    $state['steps'][$name]['status'] = 'failed';
                    $state['steps'][$name]['error'] = 'step.budget_exceeded';

                    continue;
                }
                if (! $ready && ! $overdue) {
                    continue;
                }
                // Fault: a step that fails on its Node, as a failed command would.
                if ($ready && in_array($name, $operation->input['faults']['fail_step'] ?? [], true)) {
                    $state['steps'][$name]['status'] = 'failed';
                    $state['steps'][$name]['error'] = 'spike.fault_injected';

                    continue;
                }
                $state['steps'][$name]['status'] = 'sent';
                $state['steps'][$name]['first_sent_ms'] ??= $now;
                // A step's deadline follows its expected duration, so a long step is not re-sent while it still runs.
                $state['steps'][$name]['resend_ms'] = $now + 1000 * ($step['resend_s'] ?? 4);
                $state['steps'][$name]['sends'][] = $now;
                (int) $step['node'] === 0 ? $locals[] = $name : $sends[] = $name;
                $resent += $overdue ? 1 : 0;
            }
            $statuses = array_column($state['steps'], 'status');
            $finished = in_array('failed', $statuses, true) ? 'failed' : (array_all($statuses, fn ($s) => $s === 'done') ? 'succeeded' : null);
            if ($finished !== null) {
                $state['finished_ms'] = $now;
                $sends = $locals = [];
            }
            $operation->forceFill(['state' => $state, 'status' => $finished ?? 'running'])->save();
            $snapshot = $operation;
        });
        if ($snapshot === null) {
            return 0;
        }
        $definition = $snapshot->input['definition'] ?? null;
        $unsent = array_values(array_filter($sends, static fn (string $name): bool => ! self::send($snapshot, $name, $definition)));
        if ($unsent !== []) {
            // A lost publish is the one hop JetStream cannot cover. The step goes back to pending, and the caller learns it: the receipt
            // handler NAKs, so JetStream redelivers the receipt and the next advance sends the step again. No timer needed.
            DB::transaction(static function () use ($operationId, $unsent): void {
                $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
                $state = $operation->state;
                foreach ($unsent as $name) {
                    $state['steps'][$name]['status'] = 'pending';
                    $state['steps'][$name]['publish_failed'] = ($state['steps'][$name]['publish_failed'] ?? 0) + 1;
                }
                $operation->forceFill(['state' => $state])->save();
            });
            throw new \RuntimeException('spike.publish_failed: '.implode(',', $unsent));
        }
        foreach ($locals as $name) {
            self::runLocal($operationId, $name, $definition);
        }
        if ($finished !== null && $definition !== null) {
            $definition::finished($snapshot->refresh(), $finished);
        }

        return $resent;
    }

    /** Runs one Gateway step outside the transaction, then records its result like a receipt. */
    private static function runLocal(int $operationId, string $name, string $definition): void
    {
        $operation = Operation::query()->findOrFail($operationId);
        try {
            $output = $definition::local($name, $operation);
            $line = ['status' => 'done', 'target' => $name, 'stdout' => $output];
        } catch (Throwable $exception) {
            report($exception);
            $line = ['status' => 'failed', 'target' => $name, 'code' => 'gateway.'.$name.'_failed'];
        }
        DB::transaction(static function () use ($operationId, $name, $line): void {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
            $state = $operation->state;
            $state['steps'][$name] = self::record($state['steps'][$name], $line['status'], $line);
            $operation->forceFill(['state' => $state])->save();
        });
        self::advance($operationId);
    }

    /** Signs and publishes one instruction to its Node over the observer's WebSocket. */
    private static function send(Operation $operation, string $name, ?string $definition): bool
    {
        try {
            $step = $operation->input['steps'][$name];
            $input = $definition !== null ? $definition::input($name, $operation) : ($step['input'] ?? []);
            $addressed = $operation->replicate();
            $addressed->id = $operation->id;
            $addressed->exists = true;
            $addressed->node_id = (int) $step['node']; // In memory only: the order goes to this step's Node.
            $order = ExecutorOrderSigner::fromConfig()->sign($addressed, $step['action'], self::target($operation->id, $name), $input);
            if (Cache::get('spike:transport') === 'nats') {
                \App\Spike\Nats\Bus::publishOrder((int) $step['node'], $operation->id, $order->wire, $order->nonce);
            } else {
                app(ExecutorOrderPublisher::class)->publish($addressed, $order);
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** The executor's target: unique per operation and step, so steps of different operations never wait on each other. */
    public static function target(int $operationId, string $name): string
    {
        return substr($name, 0, 100).'.op'.$operationId;
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
