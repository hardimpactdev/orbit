<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Server-owned capabilities and evidence for one admitted kind.
 *
 * @phpstan-type RunContract array{transport: string, name: string, input_fields?: list<string>, input_values?: array<string, mixed>, bind?: array<string, string>, secrets?: array<string, string>, resumable?: bool, uncertain_error_code?: string, node_field?: string, step?: string, required?: bool, input_schema?: array<string, mixed>}
 * @phpstan-type SwampDefinition array{executor: 'swamp', completion: class-string, execution?: class-string<OperationFamilyExecution>, runs: list<RunContract>, completion_contract: array<string,mixed>, result_fields: list<string|array<string,mixed>>, admission?:class-string<OperationChildAdmission>, children?:list<array{kind:string,node_from:string,bind?:array<string,string>}>,compensations?:list<string>,cancellation_boundary?:string}
 *
 * @phpstan-import-type RunInput from SwampOperationExecutor
 */
final readonly class OperationExecutionContract
{
    public function __construct(private CommandActivityInputSanitizer $redactor, private OperationKinds $kinds, private OperationSecrets $secrets, private OperationTransports $transports) {}

    /** @return SwampDefinition */
    private function definition(Operation $operation): array
    {
        $definition = $this->kinds->definition($operation->kind);
        if (! is_array($definition)) {
            throw new LogicException('A Swamp kind requires an execution and completion contract.');
        }

        return $definition;
    }

    /** @param RunInput $data
     * @return RunContract
     */
    public function authorizeRun(Operation $operation, array $data): array
    {
        foreach ($this->definition($operation)['runs'] as $run) {
            if ($run['transport'] !== $data['transport'] || $run['name'] !== $data['name']) {
                continue;
            }
            $execution = $this->execution($operation);
            if ($execution !== null) {
                $descriptors = $execution->context($operation)['descriptors'];
                $descriptor = array_find($descriptors, static fn (array $entry): bool => $entry['key'] === $data['key'] && $entry['name'] === $data['name'] && $entry['transport'] === $data['transport']);
                if ($descriptor === null) {
                    continue;
                }
                $run['resumable'] = ($run['resumable'] ?? false) && $descriptor['resumable'];
                $run['step'] = $descriptor['step'];
                $run['required'] = $descriptor['required'];
            }
            $expected = $execution?->input($operation, $data) ?? [...Arr::only($operation->input, $run['input_fields'] ?? []), ...($run['input_values'] ?? [])];
            foreach ($run['bind'] ?? [] as $parameter => $path) {
                if (! str_starts_with($path, 'input.') || ! Arr::has($operation->input, substr($path, 6))) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The admitted input binding is missing.', 422);
                }
                Arr::set($expected, $parameter, Arr::get($operation->input, substr($path, 6)));
            }
            if (isset($run['node_field'])) {
                $expected[$run['node_field']] = app(OperationChildren::class)->nodeId($operation);
            }
            if (($data['transport'] === 'gateway' || app(OperationChildren::class)->nodeId($operation) !== null) && $this->same($expected, $data['input'])) {
                return $run;
            }
        }

        throw new ResourceOperationException('operation.execution_forbidden', 'The invocation does not match the Operation contract.', 422);
    }

    public function execution(Operation $operation): ?OperationFamilyExecution
    {
        $class = $this->definition($operation)['execution'] ?? null;

        return $class === null ? null : app($class);
    }

    /** @return array{descriptors: list<array{key: string, step: string, transport: string, name: string, input: array<string, mixed>, resumable: bool, required: bool}>, result?: array<string, mixed>} */
    public function context(Operation $operation): array
    {
        $context = $this->execution($operation)?->context($operation) ?? ['descriptors' => []];
        foreach ($context['descriptors'] as $descriptor) {
            $this->authorizeRun($operation, ['worker' => $operation->worker ?? '', 'key' => $descriptor['key'],
                'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']]);
        }

        return ['descriptors' => $context['descriptors'],
            ...(isset($context['result']) ? ['result' => $this->result($operation, $context['result'])] : [])];
    }

    /** @param RunInput $data
     * @param  RunContract  $spec
     * @param  array<string, mixed>  $input
     */
    public function validateHydratedInput(Operation $operation, array $data, array $spec, #[\SensitiveParameter] array $input): void
    {
        try {
            $transport = $this->transports->get($data['transport']);
            $schema = $transport->schema($operation, $data);
            $wire = $transport instanceof OperationTransportInput
                ? $transport->wireInput($operation, $data, $input)
                : json_decode(json_encode((object) $input, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            if ($schema !== true && $schema !== [] && AgentSchemas::errors($wire, $schema) === []) {
                return;
            }
        } catch (\Throwable) {
            // Only the fixed refusal leaves this boundary, never hydrated values or schema details.
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The invocation does not match the command schema.', 422);
    }

    public function cancellationAllowsRun(Operation $operation): bool
    {
        if ($operation->cancel_requested_at === null) {
            return true;
        }
        if (isset($this->definition($operation)['compensations'])) {
            return false;
        }
        $boundary = $this->execution($operation)?->cancellationBoundary($operation);
        if ($boundary === null) {
            return false;
        }
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $receipt = $runs[$boundary['key']] ?? null;
        if (! is_array($receipt) || ($receipt['transport'] ?? null) !== $boundary['transport'] || ($receipt['name'] ?? null) !== $boundary['name']) {
            return false;
        }
        $result = $receipt['result'] ?? null;

        return is_array($result) && ! isset($result['error_code']) && $this->same(Arr::only($result, array_keys($boundary['result'])), $boundary['result']);
    }

    /**
     * Keep only declared scalar leaves and bounded containers. Never retain an open object or a raw program log.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $knownValues
     * @return array<string, mixed>
     */
    public function result(Operation $operation, #[\SensitiveParameter] array $result, #[\SensitiveParameter] array $knownValues = []): array
    {
        $fields = $this->definition($operation)['result_fields'];
        $safe = OperationResultFields::filter($fields, $result);
        $safe = ValidatedData::object($this->secrets->redact($this->redactor->sanitizeProperties($safe), $knownValues));

        return OperationResultFields::filter($fields, $safe);
    }

    /** @param array<string,mixed> $result */
    public function assertResultWire(Operation $operation, #[\SensitiveParameter] array $result, #[\SensitiveParameter] mixed $wire): void
    {
        OperationResultFields::assertWireContainers($this->definition($operation)['result_fields'], $result, $wire);
    }

    public function text(string $text): string
    {
        return $this->redactor->redactText($text);
    }

    /**
     * A caller cannot manufacture successful evidence by posting a step.
     *
     * @param  array<string, mixed>  $result
     */
    public function assertStep(Operation $operation, string $name, array $result): void
    {
        if ($this->execution($operation) !== null) {
            $proven = array_any(ValidatedData::object($operation->state['executor_runs'] ?? []), fn ($receipt) => is_array($receipt) && ($receipt['step'] ?? null) === $name && is_array($receipt['result'] ?? null)
                && ! isset($receipt['result']['error_code']) && $this->same($receipt['result'], $result));
            if (! $proven) {
                throw new ResourceOperationException('operation.completion_unproven', 'The descriptor step has no matching keyed transport receipt.', 409);
            }
        }
        $contract = OperationCompletionContracts::select($operation, $this->definition($operation)['completion_contract']);
        if (! isset($contract['step'], $contract['transport'], $contract['name'], $contract['result']) || $name !== $contract['step']) {
            return;
        }
        foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $run) {
            if (! is_array($run) || ($run['transport'] ?? null) !== $contract['transport'] || ($run['name'] ?? null) !== $contract['name']) {
                continue;
            }
            $recorded = $run['result'] ?? null;
            if (is_array($recorded) && ! isset($recorded['error_code'])
                && $this->same(Arr::only($recorded, array_keys($contract['result'])), $contract['result'])
                && $this->same($recorded, $result)) {
                return;
            }
        }

        throw new ResourceOperationException('operation.completion_unproven', 'A successful Node receipt is required.', 409);
    }

    /** @param array<string, mixed> $result */
    public function assertCompletion(Operation $operation, array $result): void
    {
        $contract = OperationCompletionContracts::select($operation, $this->definition($operation)['completion_contract']);
        if (isset($contract['children_settled'])) {
            app(OperationChildren::class)->assertSettled($operation, $contract['children_settled'], $result);
        }
        if (isset($contract['children_succeeded'])) {
            app(OperationChildren::class)->assertSucceeded($operation, $contract['children_succeeded']);
        }
        if (! isset($contract['step'])) {
            return;
        }
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        $stepResult = $steps[$contract['step']] ?? null;
        if (! is_array($stepResult) || ! $this->same($stepResult, $result)
            || ! $operation->steps()->where('name', $contract['step'])->where('status', OperationStepStatus::Succeeded->value)->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'A successful Node step is required.', 409);
        }
        $this->assertStep($operation, $contract['step'], $result);
    }

    /** @param array<string,mixed> $result */
    public function assertSettledChildren(Operation $operation, array $result, bool $successful): void
    {
        $contract = OperationCompletionContracts::select($operation, $this->definition($operation)['completion_contract']);
        if (isset($contract['children_settled'])) {
            app(OperationChildren::class)->assertSettled($operation, $contract['children_settled'], $result, $successful);
        }
    }

    /** @param array<array-key, mixed> $left
     * @param  array<array-key, mixed>  $right
     */
    private function same(array $left, array $right): bool
    {
        return $this->ordered($left) === $this->ordered($right);
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function ordered(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->ordered($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
