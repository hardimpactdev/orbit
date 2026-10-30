<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Arr;
use Throwable;

/** Extracts transient outputs into existing family-owned encrypted records using their registered owner checks and record writes. */
final readonly class OperationSecretOutputs
{
    /** @param iterable<OperationSecretOutputStore> $stores */
    public function __construct(#[Tag(OperationSecretOutputStore::class)] private iterable $stores, private OperationKinds $kinds) {}

    /** @return list<array{name:string,secret:true,store:string}> */
    private function fields(Operation $operation): array
    {
        $definition = $this->kinds->definition($operation->kind);

        return is_array($definition) ? self::declarations($definition['result_fields']) : [];
    }

    /** @param list<string|array<string,mixed>> $fields
     * @return list<array{name:string,secret:true,store:string}>
     */
    public static function declarations(array $fields): array
    {
        $outputs = [];
        foreach ($fields as $field) {
            if (is_array($field) && ($field['secret'] ?? false) === true) {
                $outputs[] = ['name' => ValidatedData::string($field['name']), 'secret' => true, 'store' => ValidatedData::string($field['store'])];
            }
        }

        return $outputs;
    }

    /** @return list<string> Public declaration paths, never values. */
    public function paths(Operation $operation): array
    {
        return array_column($this->fields($operation), 'name');
    }

    public function authorize(Operation $operation): void
    {
        foreach ($this->fields($operation) as $field) {
            $this->owner($operation, $this->store($field['store']));
        }
    }

    /** @param array<string,mixed> $result
     * @return array{result:array<string,mixed>,values:list<string>}
     */
    public function extract(Operation $operation, #[\SensitiveParameter] array $result): array
    {
        $values = [];
        $bytes = 0;
        foreach ($this->fields($operation) as $field) {
            $entry = self::atPath($result, $field['name']);
            if (! $entry['found']) {
                continue;
            }
            $value = $entry['value'];
            if ($value === ['$secret_output' => true]) {
                throw new ResourceOperationException('operation.execution_uncertain', 'The target confirms an output but its value was not stored.', 409);
            }
            try {
                $bytes += strlen(json_encode($value, JSON_THROW_ON_ERROR));
                if ($bytes > OperationResultFields::MaximumResultBytes) {
                    $this->refuse();
                }
                $reference = $this->write($operation, $field['store'], $value);
            } catch (ResourceOperationException $exception) {
                throw OperationSecrets::refusal($exception);
            } catch (Throwable) {
                $this->refuse();
            }
            $values = [...$values, ...new OperationSecretValue($value)->strings()];
            Arr::set($result, $field['name'], $reference);
        }

        return ['result' => $result, 'values' => $values];
    }

    /** Write one transient output through its registered owner store. No Operation state or receipt.
     * @return array{'$secret':string,id:int}
     */
    public function write(Operation $operation, string $name, #[\SensitiveParameter] mixed $value): array
    {
        try {
            $protected = new OperationSecretValue($value);
            if (self::containsBoolean($value) || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > OperationResultFields::MaximumResultBytes
                || ($protected->requiresRedaction() && $protected->strings() === [])) {
                $this->refuse();
            }
            $store = $this->store($name);
            $owner = $this->owner($operation, $store);
            $store->store($operation, $owner, $value);

            return ['$secret' => $name, 'id' => $owner];
        } catch (ResourceOperationException $exception) {
            throw OperationSecrets::refusal($exception);
        } catch (Throwable) {
            $this->refuse();
        }
    }

    private static function containsBoolean(#[\SensitiveParameter] mixed $value): bool
    {
        return is_bool($value) || (is_array($value) && array_any($value, self::containsBoolean(...)));
    }

    /** Read the same segmented path as the agent's JSON pointer, ignoring literal dotted aliases.
     * @param  array<string,mixed>  $result
     * @return array{found:bool,value:mixed}
     */
    public static function atPath(#[\SensitiveParameter] array $result, string $path): array
    {
        $value = $result;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return ['found' => false, 'value' => null];
            }
            $value = $value[$segment];
        }

        return ['found' => true, 'value' => $value];
    }

    /** @param array{name:string,secret:true,store:string} $field
     * @return array{'$secret':string,id:int}
     */
    public static function reference(array $field, #[\SensitiveParameter] mixed $value): array
    {
        if (! is_array($value) || count($value) !== 2 || ($value['$secret'] ?? null) !== $field['store']
            || ! is_int($value['id'] ?? null) || $value['id'] < 1) {
            throw new ResourceOperationException('operation.secret_output_unproven', 'Secret output results require an owning record reference.', 422);
        }

        return ['$secret' => $field['store'], 'id' => $value['id']];
    }

    /** @param list<string|array<string,mixed>> $fields
     * @param  array<string,mixed>  $result
     * @return array<string,array{'$secret':string,id:int}>
     */
    public static function references(array $fields, #[\SensitiveParameter] array $result): array
    {
        $references = [];
        foreach (self::declarations($fields) as $field) {
            $entry = self::atPath($result, $field['name']);
            if ($entry['found']) {
                $references[$field['name']] = self::reference($field, $entry['value']);
            }
        }

        return $references;
    }

    private function store(string $name): OperationSecretOutputStore
    {
        $matches = [];
        foreach ($this->stores as $store) {
            if ($store->name() === $name) {
                $matches[] = $store;
            }
        }
        if (count($matches) !== 1) {
            $this->refuse();
        }

        return $matches[0];
    }

    private function owner(Operation $operation, OperationSecretOutputStore $store): int
    {
        try {
            if (! in_array($operation->target_type, $store->targetTypes(), true)) {
                $this->refuse();
            }
            $owner = $store->ownerId($operation);
            if ($owner === null || $owner < 1) {
                $this->refuse();
            }

            return $owner;
        } catch (ResourceOperationException $exception) {
            throw OperationSecrets::refusal($exception);
        } catch (Throwable) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.secret_output_unavailable', 'The secret output could not be stored safely.', 409);
    }
}
