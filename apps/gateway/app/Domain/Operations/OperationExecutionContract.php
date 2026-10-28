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
 * @phpstan-type RunContract array{transport: string, name: string, input_fields: list<string>, input_values: array<string, mixed>, node_field?: string, input_schema?: array<string, mixed>}
 * @phpstan-type SwampDefinition array{executor: 'swamp', completion: class-string, runs: list<RunContract>, completion_contract: array{step: string, transport: string, name: string, result: array<string, mixed>}, result_fields: list<string>}
 *
 * @phpstan-import-type RunInput from SwampOperationExecutor
 */
final readonly class OperationExecutionContract
{
    public function __construct(private CommandActivityInputSanitizer $redactor) {}

    /** @return SwampDefinition */
    private function definition(Operation $operation): array
    {
        $definition = OperationKinds::definitions()[$operation->kind] ?? null;
        if (! is_array($definition)) {
            throw new LogicException('A Swamp kind requires an execution and completion contract.');
        }

        return $definition;
    }

    /** @param RunInput $data */
    public function authorizeRun(Operation $operation, array $data): void
    {
        foreach ($this->definition($operation)['runs'] as $run) {
            if ($run['transport'] !== $data['transport'] || $run['name'] !== $data['name']) {
                continue;
            }
            $expected = Arr::only($operation->input, $run['input_fields']);
            $expected = [...$expected, ...$run['input_values']];
            if (isset($run['node_field'])) {
                $expected[$run['node_field']] = $operation->node_id;
            }
            try {
                if ($run['transport'] === 'agent') {
                    AgentSchemas::assertInput($run['name'], $data['input']);
                } elseif (! isset($run['input_schema']) || AgentSchemas::errors(json_decode(json_encode((object) $data['input'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR), $run['input_schema']) !== []) {
                    continue;
                }
            } catch (LogicException) {
                continue;
            }
            if ($operation->node_id !== null && $this->same($expected, $data['input'])) {
                return;
            }
        }

        throw new ResourceOperationException('operation.execution_forbidden', 'The invocation does not match the Operation contract.', 422);
    }

    /**
     * Keep only declared scalar leaves. Never retain an open object or a raw program log.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function result(Operation $operation, array $result): array
    {
        $safe = [];
        foreach ($this->definition($operation)['result_fields'] as $path) {
            $value = Arr::get($result, $path);
            if (Arr::has($result, $path) && (is_scalar($value) || $value === null)) {
                Arr::set($safe, $path, $value);
            }
        }

        return ValidatedData::object($this->redactor->sanitizeProperties($safe));
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
        $contract = $this->definition($operation)['completion_contract'];
        if ($name !== $contract['step']) {
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
        $contract = $this->definition($operation)['completion_contract'];
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        $stepResult = $steps[$contract['step']] ?? null;
        if (! is_array($stepResult) || ! $this->same($stepResult, $result)
            || ! $operation->steps()->where('name', $contract['step'])->where('status', OperationStepStatus::Succeeded->value)->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'A successful Node step is required.', 409);
        }
        $this->assertStep($operation, $contract['step'], $result);
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
