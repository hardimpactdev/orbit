<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Small keyed writes on the existing Operation store. Services owns execution and recovery.
 *
 * @phpstan-type StepInput array{worker: string, key: string, name: string, status: string, output?: string, result?: array<string, mixed>, error_code?: string|null}
 * @phpstan-type CompletionInput array{worker: string, status: string, result?: array<string, mixed>, error_code?: string|null}
 * @phpstan-type RunInput array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>}
 */
final readonly class SwampOperationExecutor
{
    public const int LeaseSeconds = 120;

    public function __construct(private OperationStore $store, private OperationExecutors $executors, private OperationExecutionContract $contract, private OperationSecrets $secrets, private OperationTransports $transports) {}

    public function claim(string $kind, string $worker): ?Operation
    {
        if (! $this->executors->swamp($kind)) {
            throw new ResourceOperationException('operation.executor_invalid', 'This kind is not executed by Swamp.', 422);
        }

        return DB::transaction(function () use ($kind, $worker): ?Operation {
            $operation = Operation::query()->where('kind', $kind)->where('status', OperationStatus::Running->value)
                ->where('worker', $worker)->first();
            $operation ??= Operation::query()->where('kind', $kind)
                ->where(function ($query): void {
                    $query->where('status', OperationStatus::Queued->value)
                        ->orWhere(function ($query): void {
                            $query->where('status', OperationStatus::Running->value)
                                ->where('updated_at', '<=', Carbon::now()->subSeconds(self::LeaseSeconds));
                        });
                })->orderBy('id')->first();
            if ($operation === null) {
                return null;
            }
            $this->store->change($operation, [
                'status' => OperationStatus::Running, 'worker' => $worker,
                'attempts' => $operation->attempts + ($operation->worker === $worker ? 0 : 1),
                'started_at' => $operation->started_at ?? Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $operation;
        });
    }

    public function heartbeat(Operation $operation, string $worker): Operation
    {
        return $this->owned($operation, $worker, function (Operation $operation): void {
            $this->store->change($operation, ['updated_at' => Carbon::now()]);
        });
    }

    /** @param StepInput $data */
    public function step(Operation $operation, array $data): Operation
    {
        return $this->owned($operation, $data['worker'], function (Operation $operation) use ($data): void {
            $state = $operation->state ?? [];
            $fingerprint = hash('sha256', json_encode(array_diff_key($data, ['worker' => true]), JSON_THROW_ON_ERROR));
            $data['result'] = $this->contract->result($operation, $data['result'] ?? []);
            $data['output'] = $this->contract->text($data['output'] ?? '', $operation);
            $data['error_code'] = isset($data['error_code']) ? $this->contract->text($data['error_code'], $operation) : null;
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
    public function complete(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $receipt = ['status' => $data['status'], 'result' => $this->contract->result($operation, $data['result'] ?? []),
                'error_code' => isset($data['error_code']) ? $this->contract->text($data['error_code'], $operation) : null];
            if ($operation->status->terminal()) {
                if ($operation->worker !== $data['worker'] || ($operation->state['executor_completion'] ?? null) !== $receipt) {
                    throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another completion receipt.', 409);
                }

                return $operation;
            }

            return $this->owned($operation, $data['worker'], function (Operation $operation) use ($receipt): void {
                if ($operation->steps()->where('status', OperationStepStatus::Running->value)->exists()) {
                    throw new ResourceOperationException('operation.step_running', 'Finish the current step before completion.', 409);
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
                    'failed_step' => $operation->steps()->where('status', OperationStepStatus::Failed->value)->value('name'),
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
    public function run(Operation $operation, array $data): array
    {
        $result = null;
        $held = app(OperationAttemptLease::class)->hold($operation->id, function () use ($operation, $data, &$result): void {
            $result = $this->invoke($operation, $data);
        });
        if (! $held || $result === null) {
            throw new ResourceOperationException('operation.execution_busy', 'A named invocation still runs for this Operation.', 409);
        }

        return $result;
    }

    /** @param RunInput $data
     * @return array<string, mixed>
     */
    private function invoke(Operation $operation, array $data): array
    {
        $fingerprint = hash('sha256', json_encode(array_diff_key($data, ['worker' => true]), JSON_THROW_ON_ERROR));
        $receipt = null;
        $spec = null;
        $references = [];
        $this->owned($operation, $data['worker'], function (Operation $operation) use ($data, $fingerprint, &$receipt, &$spec, &$references): void {
            $spec = $this->contract->authorizeRun($operation, $data);
            $references = $this->secrets->references($operation, $data['input'], $spec['secrets'] ?? []);
            $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
            $value = $runs[$data['key']] ?? null;
            $receipt = $value === null ? null : ValidatedData::object($value);
            if ($receipt !== null && $receipt['fingerprint'] !== $fingerprint) {
                throw new ResourceOperationException('operation.idempotency_conflict', 'The execution key has another payload.', 409);
            }
            if ($receipt === null) {
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
        $result = null;
        $values = $this->contract->redactions($operation);
        if ($receipt !== null && ($receipt['started'] ?? false) === true && ! ($spec['resumable'] ?? true)) {
            $result = ['error_code' => $spec['uncertain_error_code'] ?? 'operation.execution_uncertain'];
        } else {
            $this->owned($operation, $data['worker'], function (Operation $operation) use ($data): void {
                if ($this->contract->cancellationAllowsRun($operation)) {
                    $this->contract->execution($operation)?->beforeRun($operation, $data);
                }
            });
            if (! $this->contract->cancellationAllowsRun($operation)) {
                $result = ['error_code' => 'operation.cancelled'];
            } else {
                $hydrated = $this->secrets->hydrate($operation, $data['key'], $data['input'], $spec['secrets'] ?? []);
                $values = [...$values, ...$hydrated['values']];
                $input = $hydrated['input'];
                $this->contract->validateHydratedInput($operation, $data, $spec, $input);
                $transport = $this->transports->get($data['transport']);
                $invoke = static fn (): array => $transport->run($operation, $data, $input);
                $this->owned($operation, $data['worker'], function (Operation $operation) use ($data): void {
                    if ($this->contract->cancellationAllowsRun($operation)) {
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
                    throw new ResourceOperationException('operation.execution_failed', 'The named invocation did not return a receipt.', 502);
                }
            }
        }
        $result = ValidatedData::object($this->secrets->redact($result, $values));
        $this->owned($operation, $data['worker'], function (Operation $operation) use ($data, $values, &$result): void {
            $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
            $result = $this->contract->execution($operation)?->received($operation, $data, $result) ?? $result;
            $result = $this->contract->result($operation, $result, $values);
            $state = $operation->state ?? [];
            $entry = ValidatedData::object($runs[$data['key']]);
            $runs[$data['key']] = [...$entry, 'result' => $result];
            $state['executor_runs'] = $runs;
            $this->store->change($operation, ['state' => $state]);
        });

        return $result;
    }

    /** @param Closure(Operation): void $write */
    private function owned(Operation $operation, string $worker, Closure $write): Operation
    {
        return DB::transaction(function () use ($operation, $worker, $write): Operation {
            $operation->refresh();
            if (! $this->executors->swamp($operation->kind) || $operation->status !== OperationStatus::Running
                || $operation->worker !== $worker || $operation->updated_at?->lessThanOrEqualTo(Carbon::now()->subSeconds(self::LeaseSeconds))) {
                throw new ResourceOperationException('operation.lease_lost', 'Claim the Operation before writing it.', 409);
            }
            $write($operation);

            return $operation;
        });
    }
}
