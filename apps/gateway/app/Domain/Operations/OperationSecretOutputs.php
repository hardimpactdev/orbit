<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Arr;
use Throwable;

/** Extracts transient outputs into existing family-owned encrypted records under the keyed receipt transaction. */
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
        $booleans = [];
        $bytes = 0;
        $collectBooleans = static function (mixed $value) use (&$collectBooleans, &$booleans): void {
            if (is_bool($value)) {
                $booleans[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $collectBooleans($item);
                }
            }
        };
        foreach ($this->fields($operation) as $field) {
            if (! Arr::has($result, $field['name'])) {
                continue;
            }
            $value = Arr::get($result, $field['name']);
            if ($value === ['$secret_output' => true]) {
                throw new ResourceOperationException('operation.execution_uncertain', 'The target confirms an output but its value was not stored.', 409);
            }
            try {
                $bytes += strlen(json_encode($value, JSON_THROW_ON_ERROR));
                $protected = new OperationSecretValue($value);
                if ($bytes > OperationResultFields::MaximumResultBytes || ($protected->requiresRedaction() && $protected->strings() === [])) {
                    $this->refuse();
                }
                $store = $this->store($field['store']);
                $owner = $this->owner($operation, $store);
                $store->store($operation, $owner, $value);
            } catch (ResourceOperationException $exception) {
                throw OperationSecrets::refusal($exception);
            } catch (Throwable) {
                $this->refuse();
            }
            $collectBooleans($value);
            $values = [...$values, ...$protected->strings()];
            Arr::set($result, $field['name'], ['$secret' => $field['store'], 'id' => $owner]);
        }

        $redactBooleans = static function (mixed $value) use (&$redactBooleans, $booleans): mixed {
            if (is_bool($value) && in_array($value, $booleans, true)) {
                return '[REDACTED]';
            }

            return is_array($value) ? array_map($redactBooleans, $value) : $value;
        };

        return ['result' => array_map($redactBooleans, $result), 'values' => $values];
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
            if (Arr::has($result, $field['name'])) {
                $references[$field['name']] = self::reference($field, Arr::get($result, $field['name']));
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
