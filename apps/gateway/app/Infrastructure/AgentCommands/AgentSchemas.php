<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Support\ValidatedData;
use LogicException;
use stdClass;

/**
 * The input and output JSON Schemas the agent exports in `apps/agent/contract/schemas.json`. Gateway execution and fakes check every
 * input they receive and every answer they give against them, so a PHP test cannot send or expect a shape that the
 * Rust command would refuse or never return.
 *
 * An input is checked in its wire form, decoded with objects, because the agent tells `{}` from `[]`. An answer is
 * checked as `json_decode(..., true)` gives it to the Gateway, where an empty array is both an empty object and an
 * empty list. It knows only the keywords `schemars` writes and refuses any other, so a new keyword fails loudly.
 */
final class AgentSchemas
{
    public const string Path = __DIR__.'/../../../../agent/contract/schemas.json';

    public const array Keywords = [
        '$schema', '$defs', '$ref', 'title', 'description', 'default', 'examples', 'deprecated', 'readOnly', 'writeOnly',
        'type', 'format', 'enum', 'const', 'oneOf', 'anyOf', 'allOf', 'not',
        'properties', 'required', 'additionalProperties', 'propertyNames',
        'items', 'prefixItems', 'minItems', 'maxItems', 'uniqueItems',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'pattern',
    ];

    /** @var array<string, array{input: array<string, mixed>|bool, output: array<string, mixed>|bool}>|null */
    private static ?array $commands = null;

    /** @param  array<string, mixed>  $input */
    public static function assertInput(string $command, array $input): void
    {
        $wire = self::wireInput($input);
        self::assert($command, 'input', $wire);
    }

    /** @param array<string, mixed> $input */
    public static function wireInput(array $input): mixed
    {
        return json_decode(json_encode((object) AgentInvocation::wire($input)[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|bool */
    public static function inputSchema(string $command): array|bool
    {
        return self::commands()[$command]['input'] ?? throw new LogicException('The command has no input schema.');
    }

    public static function assertOutput(string $command, mixed $output): void
    {
        self::assert($command, 'output', json_decode(json_encode($output, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Every violation of `$schema` by `$value`, each with its JSON path. A `stdClass` is a wire object; then an array
     * is only a list.
     *
     * @param  array<string, mixed>|bool  $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array|bool $schema): array
    {
        return self::check($value, $schema, is_array($schema) ? $schema : [], '$', $value instanceof stdClass);
    }

    /**
     * @param  array<string, mixed>|bool  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private static function check(mixed $value, array|bool $schema, array $root, string $path, bool $wire): array
    {
        if ($schema === true || $schema === []) {
            return [];
        }
        if ($schema === false) {
            return ["{$path}: no value is allowed"];
        }
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::Keywords, true)) {
                throw new LogicException("Unsupported JSON Schema keyword [{$keyword}] at {$path}.");
            }
        }
        if (isset($schema['$ref'])) {
            $errors = self::check($value, self::reference(ValidatedData::string($schema['$ref']), $root), $root, $path, $wire);
            if ($errors !== []) {
                return $errors;
            }
        }

        if (isset($schema['type'])) {
            $types = is_string($schema['type']) ? [$schema['type']] : ValidatedData::stringList($schema['type']);
            if (! array_any($types, static fn (string $type): bool => self::is($value, $type, $wire))) {
                return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
            }
        }
        if (array_key_exists('const', $schema) && ! self::same($value, $schema['const'])) {
            return ["{$path}: expected ".json_encode($schema['const'])];
        }
        if (isset($schema['enum']) && ! array_any(self::values($schema['enum']), static fn (mixed $option): bool => self::same($value, $option))) {
            return ["{$path}: expected one of ".json_encode($schema['enum'])];
        }

        $errors = [];
        foreach (self::schemas($schema['allOf'] ?? []) as $part) {
            $errors = [...$errors, ...self::check($value, $part, $root, $path, $wire)];
        }
        if (isset($schema['anyOf']) && ! array_any(self::schemas($schema['anyOf']), static fn (array|bool $part): bool => self::check($value, $part, $root, $path, $wire) === [])) {
            $errors[] = "{$path}: matches none of anyOf";
        }
        if (isset($schema['oneOf'])) {
            $matches = count(array_filter(self::schemas($schema['oneOf']), static fn (array|bool $part): bool => self::check($value, $part, $root, $path, $wire) === []));
            if ($matches !== 1) {
                $errors[] = "{$path}: matches {$matches} of oneOf";
            }
        }
        if (isset($schema['not']) && self::check($value, self::schema($schema['not']), $root, $path, $wire) === []) {
            $errors[] = "{$path}: matches a forbidden schema";
        }

        if (self::is($value, 'object', $wire) && (isset($schema['properties']) || isset($schema['required']) || isset($schema['additionalProperties']) || isset($schema['propertyNames']))) {
            $errors = [...$errors, ...self::object((array) $value, $schema, $root, $path, $wire)];
        }
        if (is_array($value) && array_is_list($value) && (isset($schema['items']) || isset($schema['prefixItems']))) {
            $errors = [...$errors, ...self::list($value, $schema, $root, $path, $wire)];
        }
        if (is_array($value) && array_is_list($value)) {
            if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map(static fn (mixed $item): string => json_encode($item, JSON_THROW_ON_ERROR), $value))) !== count($value)) {
                $errors[] = "{$path}: items are not unique";
            }
            if (isset($schema['minItems']) && count($value) < ValidatedData::integer($schema['minItems'])) {
                $bound = ValidatedData::integer($schema['minItems']);
                $errors[] = "{$path}: fewer than {$bound} items";
            }
            if (isset($schema['maxItems']) && count($value) > ValidatedData::integer($schema['maxItems'])) {
                $bound = ValidatedData::integer($schema['maxItems']);
                $errors[] = "{$path}: more than {$bound} items";
            }
        }
        if (is_int($value) || is_float($value)) {
            $unsigned = is_string($schema['format'] ?? null) && str_starts_with($schema['format'], 'uint');
            $minimum = isset($schema['minimum']) ? self::number($schema['minimum']) : ($unsigned ? 0 : null);
            if ($minimum !== null && $value < $minimum) {
                $errors[] = "{$path}: below {$minimum}";
            }
            if (isset($schema['maximum']) && $value > self::number($schema['maximum'])) {
                $bound = self::number($schema['maximum']);
                $errors[] = "{$path}: above {$bound}";
            }
            if (isset($schema['exclusiveMinimum']) && $value <= self::number($schema['exclusiveMinimum'])) {
                $bound = self::number($schema['exclusiveMinimum']);
                $errors[] = "{$path}: not above {$bound}";
            }
            if (isset($schema['exclusiveMaximum']) && $value >= self::number($schema['exclusiveMaximum'])) {
                $bound = self::number($schema['exclusiveMaximum']);
                $errors[] = "{$path}: not below {$bound}";
            }
        }
        if (is_string($value)) {
            $address = match ($schema['format'] ?? null) {
                'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
                'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
                'ip' => filter_var($value, FILTER_VALIDATE_IP),
                default => true,
            };
            if ($address === false) {
                $errors[] = "{$path}: not an {$schema['format']} address";
            }
            if (isset($schema['minLength']) && mb_strlen($value) < ValidatedData::integer($schema['minLength'])) {
                $bound = ValidatedData::integer($schema['minLength']);
                $errors[] = "{$path}: shorter than {$bound}";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > ValidatedData::integer($schema['maxLength'])) {
                $bound = ValidatedData::integer($schema['maxLength']);
                $errors[] = "{$path}: longer than {$bound}";
            }
            if (isset($schema['pattern'])) {
                $pattern = ValidatedData::string($schema['pattern']);
                if (preg_match('/'.str_replace('/', '\\/', $pattern).'/u', $value) !== 1) {
                    $errors[] = "{$path}: does not match {$pattern}";
                }
            }
        }

        return $errors;
    }

    private static function assert(string $command, string $direction, mixed $value): void
    {
        $schema = self::commands()[$command][$direction] ?? throw new LogicException("The agent contract has no {$direction} schema for [{$command}].");
        $errors = self::errors($value, $schema);
        if ($errors !== []) {
            throw new LogicException("The {$direction} of agent command [{$command}] does not match its schema:\n".implode("\n", array_slice($errors, 0, 10)));
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private static function object(array $value, array $schema, array $root, string $path, bool $wire): array
    {
        $errors = [];
        foreach (ValidatedData::stringList($schema['required'] ?? []) as $name) {
            if (! array_key_exists($name, $value)) {
                $errors[] = "{$path}: missing [{$name}]";
            }
        }
        $properties = ValidatedData::object($schema['properties'] ?? []);
        foreach ($value as $name => $item) {
            $name = (string) $name;
            if (isset($schema['propertyNames'])) {
                $errors = [...$errors, ...self::check($name, self::schema($schema['propertyNames']), $root, "{$path}.{$name}", $wire)];
            }
            if (isset($properties[$name])) {
                $errors = [...$errors, ...self::check($item, self::schema($properties[$name]), $root, "{$path}.{$name}", $wire)];
            } elseif (array_key_exists('additionalProperties', $schema)) {
                $errors = [...$errors, ...($schema['additionalProperties'] === false
                    ? ["{$path}: unknown field [{$name}]"]
                    : self::check($item, self::schema($schema['additionalProperties']), $root, "{$path}.{$name}", $wire))];
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private static function list(array $value, array $schema, array $root, string $path, bool $wire): array
    {
        $errors = [];
        $prefix = self::schemas($schema['prefixItems'] ?? []);
        foreach ($value as $index => $item) {
            $itemSchema = $prefix[$index] ?? $schema['items'] ?? true;
            $errors = [...$errors, ...self::check($item, self::schema($itemSchema), $root, "{$path}[{$index}]", $wire)];
        }

        return $errors;
    }

    /** In wire form only a `stdClass` is an object; in a Gateway answer an empty or keyed array is one. */
    private static function is(mixed $value, string $type, bool $wire): bool
    {
        return match ($type) {
            'object' => $value instanceof stdClass || (! $wire && is_array($value) && ($value === [] || ! array_is_list($value))),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            // The agent refuses `5.0` for an integer; a Gateway answer decodes whole numbers to int anyway.
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => throw new LogicException("Unsupported JSON Schema type [{$type}]."),
        };
    }

    private static function same(mixed $value, mixed $expected): bool
    {
        return is_int($value) && is_float($expected) || is_float($value) && is_int($expected)
            ? (float) $value === (float) $expected
            : $value === $expected;
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>|bool
     */
    private static function reference(string $reference, array $root): array|bool
    {
        if (! str_starts_with($reference, '#/$defs/')) {
            throw new LogicException("Unsupported JSON Schema reference [{$reference}].");
        }

        $definitions = ValidatedData::object($root['$defs'] ?? []);

        return self::schema($definitions[substr($reference, 8)] ?? throw new LogicException("Unknown JSON Schema reference [{$reference}]."));
    }

    /** @return array<string, mixed>|bool */
    private static function schema(mixed $value): array|bool
    {
        return is_bool($value) ? $value : ValidatedData::object($value);
    }

    /** @return list<array<string, mixed>|bool> */
    private static function schemas(mixed $value): array
    {
        return array_map(self::schema(...), self::values($value));
    }

    /** @return list<mixed> */
    private static function values(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('A schema list must be an array.');
        }

        return $value;
    }

    private static function number(mixed $value): int|float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new LogicException('A schema bound must be a number.');
        }

        return $value;
    }

    /** @return array<string, array{input: array<string, mixed>|bool, output: array<string, mixed>|bool}> */
    private static function commands(): array
    {
        if (self::$commands === null) {
            $document = json_decode((string) file_get_contents(self::Path), true, flags: JSON_THROW_ON_ERROR);
            $commands = [];
            foreach (ValidatedData::object(ValidatedData::object($document)['commands'] ?? []) as $name => $schemas) {
                $pair = ValidatedData::object($schemas);
                $commands[$name] = ['input' => self::schema($pair['input'] ?? false), 'output' => self::schema($pair['output'] ?? false)];
            }
            self::$commands = $commands;
        }

        return self::$commands;
    }
}
