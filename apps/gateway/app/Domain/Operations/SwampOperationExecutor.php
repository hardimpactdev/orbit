<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Small keyed writes on the existing Operation store. Services owns execution and recovery.
 *
 * @phpstan-type StepInput array{worker: string, key: string, name: string, status: string, output?: string, result?: array<string, mixed>, error_code?: string|null}
 * @phpstan-type CompletionInput array{worker: string, status: string, result?: array<string, mixed>, error_code?: string|null}
 * @phpstan-type EffectRunInput array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>, effect_key: string, effect_metadata?: EffectReceiptMetadata, effect_identity?:array{nonce:string,created_at:int}}
 * @phpstan-type RunInput array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>}
 */
final readonly class SwampOperationExecutor
{
    public const int LeaseSeconds = 120;

    public function __construct(private OperationStore $store, private OperationExecutors $executors, private OperationExecutionContract $contract, private OperationSecrets $secrets, private OperationTransports $transports) {}

    public function claim(string $kind, string $worker, int $nodeId, ?int $operationId = null): ?Operation
    {
        if (! $this->executors->swamp($kind)) {
            throw new ResourceOperationException('operation.executor_invalid', 'This kind is not executed by Swamp.', 422);
        }

        return DB::transaction(function () use ($kind, $worker, $nodeId, $operationId): ?Operation {
            $operation = Operation::query()->where('kind', $kind)->when($operationId !== null, fn ($query) => $query->whereKey($operationId))->where('status', OperationStatus::Running->value)
                ->where('worker', $worker)->where('state->executor_lease_node_id', $nodeId)->first();
            if ($operation === null) {
                foreach ($this->claimCandidates($kind, $operationId, true) as $candidate) {
                    $locked = Operation::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if ($locked !== null && $this->eligible($locked, true)) {
                        $operation = $locked;
                        break;
                    }
                }
            }
            if ($operation === null) {
                return null;
            }
            $state = [...($operation->state ?? []), 'executor_lease_node_id' => $nodeId];
            $state['executor_target'] ??= ['node_id' => app(OperationChildren::class)->nodeId($operation), 'target_type' => $operation->target_type, 'target_id' => $operation->target_id];
            if ($operation->status === OperationStatus::Waiting) {
                $state['executor_reentry'] = app(OperationChildren::class)->reentry($operation);
                unset($state['executor_wait'], $state['executor_assistance']);
            }
            $this->store->change($operation, [
                'status' => OperationStatus::Running, 'worker' => $worker,
                'attempts' => $operation->attempts + ($operation->worker === $worker && ($operation->state['executor_lease_node_id'] ?? null) === $nodeId ? 0 : 1),
                'state' => $state,
                'started_at' => $operation->started_at ?? Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $operation;
        });
    }

    /** @return list<array{kind:string,claimable:int,oldest_at:string|null}> */
    public function claimable(): array
    {
        $kinds = [];
        foreach (OperationKinds::names() as $kind) {
            if (! $this->executors->swamp($kind)) {
                continue;
            }
            $candidates = $this->claimCandidates($kind);
            $oldest = null;
            $count = 0;
            foreach ($candidates as $candidate) {
                $count++;
                if ($candidate->created_at !== null && ($oldest === null || $candidate->created_at->lessThan($oldest))) {
                    $oldest = $candidate->created_at;
                }
            }
            $kinds[] = ['kind' => $kind, 'claimable' => $count, 'oldest_at' => $oldest?->toISOString()];
        }

        return $kinds;
    }

    /** @return LazyCollection<int,Operation> */
    private function claimCandidates(string $kind, ?int $operationId = null, bool $forClaim = false): LazyCollection
    {
        return Operation::query()->where('kind', $kind)
            ->when($operationId !== null, fn ($query) => $query->whereKey($operationId))
            ->where(function ($query): void {
                $query->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Waiting->value])
                    ->orWhere(function ($query): void {
                        $query->where('status', OperationStatus::Running->value)
                            ->where('updated_at', '<=', Carbon::now()->subSeconds(self::LeaseSeconds));
                    });
            })->orderBy('id')->cursor()
            ->filter(fn (Operation $candidate): bool => $this->eligible($candidate, $forClaim))->values();
    }

    private function eligible(Operation $candidate, bool $flagAssistance): bool
    {
        return match ($candidate->status) {
            OperationStatus::Queued => true,
            OperationStatus::Running => $candidate->updated_at?->lessThanOrEqualTo(Carbon::now()->subSeconds(self::LeaseSeconds)) ?? false,
            OperationStatus::Waiting => app(OperationChildren::class)->ready($candidate, $flagAssistance),
            default => false,
        };
    }

    public function heartbeat(Operation $operation, string $worker, int $nodeId): Operation
    {
        return $this->owned($operation, $worker, $nodeId, function (Operation $operation): void {
            $this->store->change($operation, ['updated_at' => Carbon::now()]);
        });
    }

    /** @param array<string,mixed> $data
     * @return array{operation:Operation,created:bool}
     */
    public function child(Operation $parent, array $data, int $nodeId): array
    {
        $result = null;
        $this->owned($parent, ValidatedData::string($data['worker']), $nodeId, function (Operation $parent) use ($data, &$result): void {
            $result = app(OperationChildren::class)->admit($parent, $data);
        });

        return $result ?? throw new \LogicException('Child admission requires an owned parent.');
    }

    /** @param list<string> $keys */
    public function wait(Operation $parent, string $worker, array $keys, int $nodeId): Operation
    {
        sort($keys);

        return DB::transaction(function () use ($parent, $worker, $keys, $nodeId): Operation {
            $parent->refresh();
            $children = app(OperationChildren::class);
            $tuples = $children->waitFor($parent, $keys);
            if ($parent->status === OperationStatus::Waiting) {
                $wait = $parent->state['executor_wait'] ?? null;
                if (! is_array($wait) || ($wait['worker'] ?? null) !== $worker || ($wait['node_id'] ?? null) !== $nodeId) {
                    throw new ResourceOperationException('operation.lease_lost', 'Claim the Operation before waiting.', 409);
                }
                if (($wait['children'] ?? null) !== $tuples) {
                    throw new ResourceOperationException('operation.idempotency_conflict', 'The parent is waiting on other children.', 409);
                }

                return $parent;
            }

            return $this->owned($parent, $worker, $nodeId, function (Operation $parent) use ($worker, $nodeId, $tuples): void {
                if ($parent->steps()->where('status', OperationStepStatus::Running->value)->exists()) {
                    throw new ResourceOperationException('operation.step_running', 'Finish the current step before waiting.', 409);
                }
                $this->store->change($parent, ['status' => OperationStatus::Waiting, 'worker' => null,
                    'state' => [...($parent->state ?? []), 'executor_lease_node_id' => null,
                        'executor_wait' => ['worker' => $worker, 'node_id' => $nodeId, 'children' => $tuples]]]);
            });
        });
    }

    /** @param StepInput $data */
    public function step(Operation $operation, array $data, int $nodeId): Operation
    {
        return $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data): void {
            $state = $operation->state ?? [];
            $fingerprint = hash('sha256', json_encode(array_diff_key($data, ['worker' => true]), JSON_THROW_ON_ERROR));
            $data['result'] = $this->contract->result($operation, $data['result'] ?? []);
            $data['output'] = $this->contract->text($data['output'] ?? '');
            $data['error_code'] = isset($data['error_code']) ? $this->contract->text($data['error_code']) : null;
            if ($data['status'] === 'succeeded') {
                $this->contract->assertStep($operation, $data['name'], $data['result']);
            }
            $events = ValidatedData::object($state['executor_events'] ?? []);
            $receipt = $events[$data['key']] ?? null;
            if ($receipt !== null) {
                if ($receipt !== $fingerprint) {
                    throw new ResourceOperationException('operation.idempotency_conflict', 'The event key has another payload.', 409);
                }

                return;
            }
            $step = $operation->steps()->where('name', $data['name'])->first();
            $step ??= new OperationStep([
                'operation_id' => $operation->id, 'position' => $operation->steps()->count() + 1,
                'name' => $data['name'], 'status' => OperationStepStatus::Pending, 'resumable' => true,
            ]);
            if (in_array($step->status, [OperationStepStatus::Succeeded, OperationStepStatus::Failed], true)) {
                throw new ResourceOperationException('operation.step_finished', 'The step has already finished.', 409);
            }
            $output = ($step->output ?? '').$data['output'];
            $events[$data['key']] = $fingerprint;
            $state['executor_events'] = $events;
            if ($data['status'] === 'succeeded') {
                $steps = ValidatedData::object($state['steps'] ?? []);
                $steps[$data['name']] = $data['result'];
                $state['steps'] = $steps;
            }
            $this->store->change($operation, [
                'state' => $state, 'current_step' => $data['status'] === 'running' ? $data['name'] : null,
            ], $step, [
                'status' => OperationStepStatus::from($data['status']),
                'started_at' => $step->started_at ?? Carbon::now(),
                'finished_at' => $data['status'] === 'running' ? null : Carbon::now(),
                'error_code' => $data['error_code'] ?? null,
                'output' => mb_scrub(substr($output, -OperationRun::OutputTailBytes), 'UTF-8'),
                'output_truncated' => $step->output_truncated || strlen($output) > OperationRun::OutputTailBytes,
            ]);
        });
    }

    /** @param CompletionInput $data */
    public function complete(Operation $operation, array $data, int $nodeId): Operation
    {
        return DB::transaction(function () use ($operation, $data, $nodeId): Operation {
            $operation->refresh();
            app(OperationChildren::class)->nodeId($operation);
            $receipt = ['status' => $data['status'], 'result' => $this->contract->result($operation, $data['result'] ?? []),
                'error_code' => isset($data['error_code']) ? $this->contract->text($data['error_code']) : null];
            if ($operation->status->terminal()) {
                if ($operation->worker !== $data['worker'] || ($operation->state['executor_lease_node_id'] ?? null) !== $nodeId || ($operation->state['executor_completion'] ?? null) !== $receipt) {
                    throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another completion receipt.', 409);
                }

                return $operation;
            }

            return $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($receipt): void {
                if ($operation->steps()->where('status', OperationStepStatus::Running->value)->exists()) {
                    throw new ResourceOperationException('operation.step_running', 'Finish the current step before completion.', 409);
                }
                $children = app(OperationChildren::class);
                $children->assertNoPending($operation);
                if ($operation->parent_operation_id !== null) {
                    $children->child(Operation::query()->findOrFail($operation->parent_operation_id), (string) $operation->child_key);
                }
                if ($receipt['status'] === 'succeeded') {
                    $this->contract->assertCompletion($operation, $receipt['result']);
                }
                $outcome = match ($receipt['status']) {
                    'succeeded' => OperationOutcome::succeeded($receipt['result']),
                    'cancelled' => OperationOutcome::cancelled($receipt['result']),
                    default => OperationOutcome::failed(ValidatedData::string($receipt['error_code']), $receipt['result']),
                };
                $this->contract->execution($operation)?->assertCompletion($operation, $outcome);
                $this->executors->completion($operation->kind)?->complete($operation, $outcome);
                $this->store->change($operation, [
                    'status' => $outcome->status, 'result' => $outcome->result, 'error_code' => $outcome->errorCode,
                    'failed_step' => $outcome->status === OperationStatus::Succeeded ? null : $operation->steps()->where('status', OperationStepStatus::Failed->value)->value('name'),
                    'current_step' => null, 'finished_at' => Carbon::now(), 'lock_key' => null,
                    'state' => [...($operation->state ?? []), 'executor_completion' => $receipt],
                ]);
            });
        });
    }

    /**
     * Repeating an uncertain invocation is safe only for convergent named programs. The family reconciles other effects.
     *
     * @param  RunInput  $data
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $data, int $nodeId): array
    {
        $result = null;
        $held = app(OperationAttemptLease::class)->hold(app(OperationChildren::class)->executionLeaseId($operation), function () use ($operation, $data, $nodeId, &$result): void {
            $result = $this->invoke($operation, $data, $nodeId);
        });
        if (! $held || $result === null) {
            throw new ResourceOperationException('operation.execution_busy', 'A named invocation still runs for this Operation.', 409);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function receipt(Operation $operation, string $key, int $nodeId): array
    {
        $effectKey = $this->effectKey($operation, $key);
        $operation->refresh();
        app(OperationChildren::class)->nodeId($operation);
        if (($operation->state['executor_lease_node_id'] ?? null) !== $nodeId) {
            throw new ResourceOperationException('operation.lease_lost', 'Claim the Operation with this executor peer before reading its receipt.', 409);
        }
        $existing = ValidatedData::object($operation->state['executor_runs'] ?? [])[$key] ?? null;
        if (is_array($existing)) {
            $this->contract->authorizeRun($operation, ['worker' => $operation->worker ?? '', 'key' => $key,
                'transport' => ValidatedData::string($existing['transport']), 'name' => ValidatedData::string($existing['name']), 'input' => ValidatedData::object($existing['input'] ?? [])]);
        } elseif (! array_any($this->contract->context($operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $key)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The step has no admitted run descriptor.', 422);
        }
        $result = null;
        $held = app(OperationAttemptLease::class)->hold(app(OperationChildren::class)->executionLeaseId($operation), function () use ($operation, $key, $effectKey, $nodeId, &$result): void {
            $operation->refresh();
            $entry = ValidatedData::object($operation->state['executor_runs'] ?? [])[$key] ?? null;
            if (! is_array($entry)) {
                $descriptor = array_find($this->contract->context($operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $key);
                if ($descriptor === null) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The step has no admitted run descriptor.', 422);
                }
                $this->contract->authorizeRun($operation, [...$descriptor, 'worker' => $operation->worker ?? '']);
                $result = $this->receiptBody($effectKey, null);

                return;
            }
            $data = ['worker' => $operation->worker ?? '', 'key' => $key, 'transport' => ValidatedData::string($entry['transport']),
                'name' => ValidatedData::string($entry['name']), 'input' => ValidatedData::object($entry['input'] ?? [])];
            $spec = $this->contract->authorizeRun($operation, $data);
            if (is_array($entry['result'] ?? null)) {
                $result = $this->receiptBody($effectKey, $entry);

                return;
            }
            $this->assertTargetExists($operation, $data['transport']);
            $this->secrets->references($operation, $data['input'], $spec['secrets'] ?? []);
            $this->owned($operation, $data['worker'], $nodeId, static function (Operation $operation): void {});
            $transport = $this->transports->get($data['transport']);
            $run = [...$data, 'effect_key' => $effectKey, 'effect_identity' => $this->effectIdentity($operation)];
            if (! $transport instanceof OperationEffectTransport || ! $transport->effectful($operation, $run)) {
                $result = $this->receiptBody($effectKey, null);

                return;
            }
            $this->assertTargetExists($operation, $data['transport']);
            try {
                $target = $transport->receipt($operation, $run, $data['input']);
            } catch (AgentCommandRefused $exception) {
                if (AgentEffectReceipts::uncertain($exception->errorCode)) {
                    throw new ResourceOperationException('operation.execution_uncertain', 'The target has no proven command result.', 409);
                }
                throw new ResourceOperationException('operation.receipt_unavailable', 'The target receipt could not be read.', 502);
            } catch (\Throwable) {
                throw new ResourceOperationException('operation.receipt_unavailable', 'The target receipt could not be read.', 502);
            }
            $this->assertReceipt($effectKey, $target, $this->effectIdentity($operation));
            if (in_array($target['state'], ['succeeded', 'failed'], true)) {
                if (AgentEffectReceipts::infrastructure(ValidatedData::string($target['result']['error_code'] ?? ''))) {
                    throw new ResourceOperationException('operation.execution_uncertain', 'The target has no proven command result.', 409);
                }
                $this->recordResult($operation, $data, ValidatedData::object($target['result']), [], $target, $nodeId);
                $entry = ValidatedData::object(ValidatedData::object($operation->state['executor_runs'] ?? [])[$key]);
                $result = $this->receiptBody($effectKey, $entry);
            } else {
                $result = $this->receiptBody($effectKey, null, $target['state']);
            }
        });
        if (! $held) {
            // The Gateway invocation owns the attempt lock until it receives or loses its reply.
            return $this->receiptBody($effectKey, null, 'running');
        }

        return $result ?? $this->receiptBody($effectKey, null);
    }

    /** @return array{nonce:string,created_at:int} */
    private function effectIdentity(Operation $operation): array
    {
        $identity = $operation->state['executor_effect_identity'] ?? null;
        if (! is_array($identity) || ! is_string($identity['nonce'] ?? null) || ! Str::isUuid($identity['nonce']) || ! is_int($identity['created_at'] ?? null)) {
            throw new ResourceOperationException('operation.receipt_unproven', 'The original Operation identity is unavailable.', 409);
        }

        return ['nonce' => $identity['nonce'], 'created_at' => $identity['created_at']];
    }

    private function assertTargetExists(Operation $operation, string $transport): void
    {
        if ($transport !== 'gateway' && ! Node::query()->whereKey(app(OperationChildren::class)->nodeId($operation))->exists()) {
            throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409);
        }
    }

    private function effectKey(Operation $operation, string $key): string
    {
        if (preg_match('/\A[a-z0-9_.:-]{1,128}\z/D', $key) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The step key is invalid.', 422);
        }

        return 'op-'.$operation->id.'.'.$key;
    }

    /** @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    private function receiptBody(string $effectKey, ?array $entry, string $state = 'unknown'): array
    {
        $result = is_array($entry['result'] ?? null) ? $entry['result'] : null;

        return ['effect_key' => $effectKey, 'state' => $result === null ? $state : ($entry['receipt_state'] ?? (isset($result['error_code']) ? 'failed' : 'succeeded')),
            'exit_code' => $result === null ? null : ($entry['exit_code'] ?? (isset($result['error_code']) ? 1 : 0)),
            'result' => $result, 'finished_at' => $entry['finished_at'] ?? null];
    }

    /** @param array<string, mixed> $target
     * @param  array{nonce:string,created_at:int}  $identity
     */
    private function assertReceipt(string $effectKey, array $target, array $identity): void
    {
        $state = $target['state'] ?? null;
        $finished = $target['finished_at'] ?? null;
        $exitCode = $target['exit_code'] ?? null;
        $terminal = in_array($state, ['succeeded', 'failed'], true);
        if ($terminal && (($target['effect_nonce'] ?? null) !== $identity['nonce'] || ($target['effect_created_at'] ?? null) !== $identity['created_at'])) {
            throw new ResourceOperationException('operation.execution_uncertain', 'The target receipt belongs to another Operation identity.', 409);
        }
        if ($terminal && ($target['sanitized'] ?? false) !== true) {
            throw new ResourceOperationException('operation.receipt_unproven', 'The target receipt has no proven sanitization.', 409);
        }
        if (($target['effect_key'] ?? null) !== $effectKey || ! in_array($state, ['succeeded', 'failed', 'running', 'unknown'], true)
            || ($terminal ? ! is_array($target['result'] ?? null) : ($target['result'] ?? null) !== null)
            || ($terminal ? ! is_int($exitCode) || ! is_string($finished) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $finished) !== 1 : $exitCode !== null || $finished !== null)) {
            throw new ResourceOperationException('operation.receipt_invalid', 'The target returned an invalid effect receipt.', 502);
        }
    }

    /** @param RunInput $data
     * @return array<string, mixed>
     */
    private function invoke(Operation $operation, array $data, int $nodeId): array
    {
        $effectKey = $this->effectKey($operation, $data['key']);
        $fingerprint = hash('sha256', json_encode(array_diff_key($data, ['worker' => true]), JSON_THROW_ON_ERROR));
        $receipt = null;
        $spec = null;
        $references = [];
        $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data, $fingerprint, &$receipt, &$spec, &$references): void {
            app(OperationChildren::class)->nodeId($operation);
            $spec = $this->contract->authorizeRun($operation, $data);
            if (! isset($operation->state['executor_effect_identity'])) {
                if ($operation->created_at === null) {
                    throw new ResourceOperationException('operation.execution_uncertain', 'The Operation has no creation time.', 409);
                }
                $this->store->change($operation, ['state' => [...($operation->state ?? []), 'executor_effect_identity' => ['nonce' => (string) Str::uuid(), 'created_at' => $operation->created_at->getTimestamp()]]]);
            }
            $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
            $value = $runs[$data['key']] ?? null;
            $receipt = $value === null ? null : ValidatedData::object($value);
            if ($receipt !== null && $receipt['fingerprint'] !== $fingerprint) {
                throw new ResourceOperationException('operation.idempotency_conflict', 'The execution key has another payload.', 409);
            }
            if ($receipt === null) {
                if ($this->contract->cancellationAllowsRun($operation)) {
                    $this->assertTargetExists($operation, $data['transport']);
                    $references = $this->secrets->references($operation, $data['input'], $spec['secrets'] ?? []);
                }
                $runs[$data['key']] = ['fingerprint' => $fingerprint, 'transport' => $data['transport'], 'name' => $data['name'], 'input' => $data['input'], 'references' => $references, 'step' => $spec['step'] ?? null, 'started' => false, 'result' => null];
                $this->store->change($operation, ['state' => [...($operation->state ?? []), 'executor_runs' => $runs]]);
            }
        });
        if ($receipt !== null && $receipt['result'] !== null) {
            return ValidatedData::object($receipt['result']);
        }
        if ($spec === null) {
            throw new \LogicException('An invocation requires its authorized run contract.');
        }
        $transport = $this->transports->get($data['transport']);
        $run = [...$data, 'effect_key' => $effectKey, 'effect_identity' => $this->effectIdentity($operation)];
        $result = null;
        $values = [];
        if ($receipt !== null && ($receipt['started'] ?? false) === true && ! ($spec['resumable'] ?? false)) {
            return ['error_code' => $spec['uncertain_error_code'] ?? 'operation.execution_uncertain'];
        } else {
            $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data): void {
                if ($this->contract->cancellationAllowsRun($operation)) {
                    app(OperationChildren::class)->assertRun($operation, false);
                    $this->contract->execution($operation)?->beforeRun($operation, $data);
                }
            });
            if (! $this->contract->cancellationAllowsRun($operation)) {
                $result = ['error_code' => 'operation.cancelled'];
            } else {
                $this->assertTargetExists($operation, $data['transport']);
                $hydrated = $this->secrets->hydrate($operation, $data['key'], $data['input'], $spec['secrets'] ?? []);
                $values = $hydrated['values'];
                $input = $hydrated['input'];
                $this->contract->validateHydratedInput($operation, $data, $spec, $input);
                $run = [...$data, 'effect_key' => $effectKey, 'effect_metadata' => EffectReceiptMetadata::hydrated($values, $this->effectIdentity($operation), $input, array_keys($references))];
                $invoke = static fn (): array => $transport->run($operation, $run, $input);
                $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data): void {
                    if ($this->contract->cancellationAllowsRun($operation)) {
                        app(OperationChildren::class)->assertRun($operation);
                        $this->contract->execution($operation)?->beforeRun($operation, $data);
                        $state = $operation->state ?? [];
                        $runs = ValidatedData::object($state['executor_runs'] ?? []);
                        $entry = ValidatedData::object($runs[$data['key']]);
                        $runs[$data['key']] = [...$entry, 'started' => true];
                        $this->store->change($operation, ['state' => [...$state, 'executor_runs' => $runs]]);
                    }
                });
                try {
                    $result = $this->contract->cancellationAllowsRun($operation) ? $invoke() : ['error_code' => 'operation.cancelled'];
                } catch (AgentCommandRefused $exception) {
                    $result = ['error_code' => $exception->errorCode];
                } catch (ResourceOperationException $exception) {
                    throw OperationSecrets::refusal($exception);
                } catch (\Throwable) {
                    // Do not report a transport exception carrying hydrated credentials.
                    if ($transport instanceof OperationEffectTransport && $transport->effectful($operation, $run)) {
                        throw new ResourceOperationException('operation.execution_uncertain', 'The effect has no proven saved result.', 409);
                    }
                    throw new ResourceOperationException('operation.execution_failed', 'The named invocation did not return a receipt.', 502);
                }
            }
        }

        if (! $transport instanceof OperationEffectTransport || ! $transport->effectful($operation, $run)) {
            $result = ValidatedData::object($this->secrets->redact($result, $values));
        }

        if ($transport instanceof OperationEffectTransport && $transport->effectful($operation, $run)) {
            $code = ValidatedData::string($result['error_code'] ?? '');
            $notStarted = AgentEffectReceipts::notStarted($code);
            $earlierUncertain = ($receipt['started'] ?? false) === true;
            if (AgentEffectReceipts::uncertain($code) || ($earlierUncertain && ($notStarted || AgentEffectReceipts::preflight($code)))) {
                throw new ResourceOperationException('operation.execution_uncertain', 'The effect has no proven saved result.', 409);
            }
            if ($notStarted) {
                $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data): void {
                    $state = $operation->state ?? [];
                    $runs = ValidatedData::object($state['executor_runs'] ?? []);
                    $runs[$data['key']] = [...ValidatedData::object($runs[$data['key']]), 'started' => false];
                    $this->store->change($operation, ['state' => [...$state, 'executor_runs' => $runs]]);
                });

                return ['error_code' => $code, 'not_started' => true];
            }
        }

        return $this->recordResult($operation, $data, $result, $values, ($run['effect_metadata'] ?? null)?->receipt() ?? [], $nodeId);
    }

    /** @param RunInput $data
     * @param  array<string, mixed>  $result
     * @param  list<string>  $values
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function recordResult(Operation $operation, array $data, #[\SensitiveParameter] array $result, #[\SensitiveParameter] array $values, array $target, int $nodeId): array
    {
        $this->owned($operation, $data['worker'], $nodeId, function (Operation $operation) use ($data, $values, $target, $nodeId, &$result): void {
            $before = $operation->state ?? [];
            $runs = ValidatedData::object($before['executor_runs'] ?? []);
            $entry = ValidatedData::object($runs[$data['key']]);
            if (is_array($entry['result'] ?? null)) {
                $result = $entry['result'];

                return;
            }
            $failed = isset($result['error_code']) || ($result['outcome'] ?? null) === 'failed';
            $resultData = $result['data'] ?? null;
            $resultExit = is_array($resultData) ? ($resultData['exit_code'] ?? null) : null;
            $exitCode = $failed && is_int($resultExit) ? $resultExit : ($failed ? 1 : 0);
            $result = $this->contract->execution($operation)?->received($operation, $data, $result) ?? $result;
            $result = ValidatedData::object($this->secrets->redact($result, $values));
            $result = $this->contract->result($operation, $result, $values);
            $state = [...$before, ...($operation->state ?? [])];
            foreach ($before as $key => $value) {
                if (str_starts_with($key, 'executor_')) {
                    $state[$key] = $value;
                }
            }
            $state['executor_lease_node_id'] = $nodeId;
            $runs[$data['key']] = [...$entry, 'result' => $result,
                'receipt_state' => $target['state'] ?? ($failed || isset($result['error_code']) ? 'failed' : 'succeeded'),
                'exit_code' => $target['exit_code'] ?? $exitCode,
                'finished_at' => $target['finished_at'] ?? Carbon::now()->toISOString()];
            $state['executor_runs'] = $runs;
            $this->store->change($operation, ['state' => $state]);
        });

        return $result;
    }

    /** @param Closure(Operation): void $write */
    private function owned(Operation $operation, string $worker, int $nodeId, Closure $write): Operation
    {
        return DB::transaction(function () use ($operation, $worker, $nodeId, $write): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (! $this->executors->swamp($operation->kind) || $operation->status !== OperationStatus::Running
                || $operation->worker !== $worker || ($operation->state['executor_lease_node_id'] ?? null) !== $nodeId || $operation->updated_at?->lessThanOrEqualTo(Carbon::now()->subSeconds(self::LeaseSeconds))) {
                throw new ResourceOperationException('operation.lease_lost', 'Claim the Operation before writing it.', 409);
            }
            $write($operation);

            return $operation;
        });
    }
}
