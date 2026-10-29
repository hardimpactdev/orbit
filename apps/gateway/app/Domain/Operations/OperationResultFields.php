<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;
use stdClass;

/** Strict bounded result containers. Scalar paths retain their existing leaf selection. */
final class OperationResultFields
{
    public const int MaximumItems = 4096;

    public const int MaximumTotalItems = 65536;

    public const int MaximumStringBytes = 1024;

    public const int MaximumResultBytes = 262144;

    /** @param list<mixed> $fields */
    public static function validate(array $fields): void
    {
        $names = [];
        $totalItems = 0;
        foreach ($fields as $field) {
            $name = is_string($field) ? $field : (is_array($field) ? ($field['name'] ?? null) : null);
            if (! is_string($name) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]*\z/D', $name) !== 1 || in_array($name, $names, true)) {
                self::invalidDeclaration();
            }
            $names[] = $name;
            if (is_string($field)) {
                continue;
            }
            if (! is_array($field)) {
                self::invalidDeclaration();
            }
            if (array_key_exists('secret', $field) || array_key_exists('store', $field)) {
                self::keys($field, ['name', 'secret', 'store']);
                if (($field['secret'] ?? null) !== true || ! is_string($field['store'] ?? null)
                    || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $field['store']) !== 1) {
                    self::invalidDeclaration();
                }

                continue;
            }
            if (isset($field['list_of'])) {
                self::keys($field, ['name', 'type', 'list_of', 'max']);
                if ((isset($field['type']) && $field['type'] !== 'list') || ! is_array($field['list_of']) || $field['list_of'] === []) {
                    self::invalidDeclaration();
                }
                self::bound($field['max'] ?? self::MaximumItems);
                $entryItems = 1;
                foreach ($field['list_of'] as $key => $rule) {
                    if (! is_string($key) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]*\z/D', $key) !== 1) {
                        self::invalidDeclaration();
                    }
                    if (is_array($rule)) {
                        self::validateScalarList($rule);
                        $entryItems += ValidatedData::integer($rule['max']);
                    } elseif (! is_string($rule) || ! self::scalarRule($rule)) {
                        self::invalidDeclaration();
                    }
                }
                $totalItems += ValidatedData::integer($field['max'] ?? self::MaximumItems) * $entryItems;
            } else {
                self::validateScalarList(array_diff_key($field, ['name' => true]));
                $totalItems += ValidatedData::integer($field['max']);
            }
            if ($totalItems > self::MaximumTotalItems) {
                self::invalidDeclaration();
            }
        }
    }

    /** @param array<array-key,mixed> $list */
    private static function validateScalarList(array $list): void
    {
        self::keys($list, ['type', 'items', 'max']);
        if (($list['type'] ?? null) !== 'list' || ! in_array($list['items'] ?? null, ['string', 'integer', 'boolean'], true) || ! array_key_exists('max', $list)) {
            self::invalidDeclaration();
        }
        self::bound($list['max']);
    }

    private static function scalarRule(string $rule): bool
    {
        $rule = str_ends_with($rule, '|null') ? substr($rule, 0, -5) : $rule;
        if (in_array($rule, ['string', 'int', 'bool'], true)) {
            return true;
        }
        if (preg_match('/\Aenum\(([^()]+)\)\z/D', $rule, $match) !== 1) {
            return false;
        }
        $values = array_map(trim(...), explode(',', $match[1]));

        return ! in_array('', $values, true) && count(array_unique($values)) === count($values);
    }

    private static function bound(mixed $max): void
    {
        if (! is_int($max) || $max < 1 || $max > self::MaximumItems) {
            self::invalidDeclaration();
        }
    }

    /** @param array<array-key,mixed> $value
     * @param  list<string>  $allowed
     */
    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            self::invalidDeclaration();
        }
    }

    private static function invalidDeclaration(): never
    {
        throw new LogicException('Operation result fields require supported bounded schemas.');
    }

    /** @param list<string|array<string,mixed>> $fields
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public static function filter(array $fields, #[\SensitiveParameter] array $result): array
    {
        $safe = [];
        foreach ($fields as $field) {
            $path = is_string($field) ? $field : ValidatedData::string($field['name']);
            if (! Arr::has($result, $path)) {
                continue;
            }
            $value = Arr::get($result, $path);
            if (is_string($field)) {
                if (is_scalar($value) || $value === null) {
                    Arr::set($safe, $path, $value);
                }
            } elseif (($field['secret'] ?? false) === true) {
                Arr::set($safe, $path, OperationSecretOutputs::reference(['name' => $path, 'secret' => true, 'store' => ValidatedData::string($field['store'])], $value));
            } else {
                self::listValue($value, $field);
                Arr::set($safe, $path, $value);
            }
        }

        try {
            $bytes = strlen(json_encode($safe, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            self::invalidResult();
        }
        if ($bytes > self::MaximumResultBytes) {
            self::invalidResult();
        }

        return $safe;
    }

    /** JSON objects with numeric keys must not become lists through associative decoding.
     * @param  list<string|array<string,mixed>>  $fields
     * @param  array<string,mixed>  $result
     */
    public static function assertWireContainers(array $fields, #[\SensitiveParameter] array $result, #[\SensitiveParameter] mixed $wire): void
    {
        foreach ($fields as $field) {
            if (is_string($field) || ($field['secret'] ?? false) === true) {
                continue;
            }
            $path = ValidatedData::string($field['name']);
            if (Arr::has($result, $path)) {
                self::wireList(data_get($wire, $path), $field);
            }
        }
    }

    /** @param array<string,mixed> $field */
    private static function wireList(#[\SensitiveParameter] mixed $value, array $field): void
    {
        if (! is_array($value)) {
            self::invalidResult();
        }
        if (! isset($field['list_of'])) {
            return;
        }
        foreach ($value as $entry) {
            if (! $entry instanceof stdClass) {
                self::invalidResult();
            }
            foreach (ValidatedData::object($field['list_of']) as $name => $rule) {
                if (is_array($rule)) {
                    self::wireList($entry->{$name} ?? null, ValidatedData::object($rule));
                }
            }
        }
    }

    /** @param array<string,mixed> $field */
    private static function listValue(#[\SensitiveParameter] mixed $value, array $field): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > ValidatedData::integer($field['max'] ?? self::MaximumItems)) {
            self::invalidResult();
        }
        $object = isset($field['list_of']) ? ValidatedData::object($field['list_of']) : null;
        foreach ($value as $entry) {
            if ($object === null) {
                self::scalarValue($entry, match ($field['items']) {
                    'integer' => 'int', 'boolean' => 'bool', default => 'string'
                });

                continue;
            }
            if (! is_array($entry) || array_diff(array_keys($entry), array_keys($object)) !== [] || array_diff(array_keys($object), array_keys($entry)) !== []) {
                self::invalidResult();
            }
            foreach ($object as $key => $rule) {
                if (is_array($rule)) {
                    self::listValue($entry[$key], ValidatedData::object($rule));
                } else {
                    self::scalarValue($entry[$key], ValidatedData::string($rule));
                }
            }
        }
    }

    private static function scalarValue(#[\SensitiveParameter] mixed $value, string $rule): void
    {
        if (str_ends_with($rule, '|null')) {
            if ($value === null) {
                return;
            }
            $rule = substr($rule, 0, -5);
        }
        $valid = match ($rule) {
            'string' => is_string($value), 'int' => is_int($value), 'bool' => is_bool($value),
            default => is_string($value) && in_array($value, array_map(trim(...), explode(',', substr($rule, 5, -1))), true),
        };
        if (! $valid || (is_string($value) && strlen($value) > self::MaximumStringBytes)) {
            self::invalidResult();
        }
    }

    /** @param list<string|array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public static function skippedField(array $fields): array
    {
        foreach ($fields as $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'skipped') {
                return $field;
            }
        }

        self::invalidDeclaration();
    }

    /** @param list<string|array<string,mixed>> $fields */
    public static function validateSkipped(array $fields, int $requiredKinds): void
    {
        $field = self::skippedField($fields);
        $schema = $field['list_of'] ?? null;
        if (! is_array($schema) || ($schema['child_id'] ?? null) !== 'int' || ($schema['reason'] ?? null) !== 'string'
            || array_diff(array_keys($schema), ['child_id', 'reason', 'key', 'kind']) !== []
            || (isset($schema['key']) && $schema['key'] !== 'string') || (isset($schema['kind']) && $schema['kind'] !== 'string')
            || ($field['max'] ?? self::MaximumItems) < $requiredKinds) {
            self::invalidDeclaration();
        }
    }

    private static function invalidResult(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The result does not match its declared bounded fields.', 422);
    }
}
