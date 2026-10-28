<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/**
 * @phpstan-import-type Descriptor from \App\Domain\Operations\OperationFamilyExecution
 *
 * @phpstan-type Condition array{step: string, field: string, equals: mixed}
 * @phpstan-type Flow array{type: 'run', key: string, when?: Condition}|array{type: 'child', key: string, kind: string, node_id: int, input: array<string, mixed>, when?: Condition, continue_on_failure?: bool}
 */
final readonly class IntegrationIntent
{
    /** @return array<string, Descriptor> */
    public static function effects(Operation $operation): array
    {
        $effects = [];
        foreach (ValidatedData::object(self::data($operation)['effects']) as $key => $raw) {
            $entry = ValidatedData::object($raw);
            $effects[$key] = ['key' => ValidatedData::string($entry['key']), 'step' => ValidatedData::string($entry['step']),
                'transport' => ValidatedData::string($entry['transport']), 'name' => ValidatedData::string($entry['name']),
                'input' => ValidatedData::object($entry['input']), 'resumable' => self::boolean($entry['resumable']), 'required' => self::boolean($entry['required'])];
        }

        return $effects;
    }

    /** @return list<Flow> */
    public static function flow(Operation $operation, string $field = 'flow'): array
    {
        $raw = self::data($operation)[$field];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('The integration flow must be a list.');
        }
        $flow = [];
        foreach ($raw as $value) {
            $entry = ValidatedData::object($value);
            $key = ValidatedData::string($entry['key']);
            $item = match ($entry['type']) {
                'run' => ['type' => 'run', 'key' => $key],
                'child' => ['type' => 'child', 'key' => $key, 'kind' => ValidatedData::string($entry['kind']), 'node_id' => ValidatedData::integer($entry['node_id']), 'input' => ValidatedData::object($entry['input'])],
                default => throw new InvalidArgumentException('Unknown integration flow entry.'),
            };
            if (isset($entry['continue_on_failure'])) {
                $item['continue_on_failure'] = self::boolean($entry['continue_on_failure']);
            }
            if (isset($entry['when'])) {
                $condition = ValidatedData::object($entry['when']);
                $item['when'] = ['step' => ValidatedData::string($condition['step']), 'field' => ValidatedData::string($condition['field']), 'equals' => $condition['equals']];
            }
            $flow[] = $item;
        }

        return $flow;
    }

    /** @return array<string, mixed> */
    public static function data(Operation $operation): array
    {
        return isset($operation->input['effects']) ? $operation->input : ValidatedData::object($operation->state['integration_intent'] ?? []);
    }

    public static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('The integration flag must be a boolean.');
        }

        return $value;
    }
}
