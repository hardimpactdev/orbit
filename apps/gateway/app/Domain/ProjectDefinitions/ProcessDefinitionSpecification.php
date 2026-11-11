<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class ProcessDefinitionSpecification
{
    private const array Collections = ['command', 'environment', 'ports', 'volumes', 'required_mount_paths'];

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function update(#[SensitiveParameter] array $stored, #[SensitiveParameter] array $changes): array
    {
        if (array_key_exists('runtime', $changes)) {
            $changes['runtime'] = self::resolve($changes['runtime'], $stored['runtime'] ?? null, 'spec.runtime');
        }
        $spec = array_key_exists('runtime', $changes) && $changes['runtime'] !== ($stored['runtime'] ?? null)
            ? [] : $stored;
        foreach ($changes as $field => $value) {
            if ($value === null && ! in_array($field, self::Collections, true)) {
                unset($spec[$field]);
            } else {
                $spec[$field] = self::resolve($value, $stored[$field] ?? null, 'spec.'.$field);
            }
        }

        return $spec;
    }

    private static function resolve(#[SensitiveParameter] mixed $value, #[SensitiveParameter] mixed $stored, string $path): mixed
    {
        if (is_string($value) && str_contains($value, '[REDACTED]')) {
            if (! is_scalar($stored) || (is_string($stored) && str_contains($stored, '[REDACTED]'))) {
                throw ValidationException::withMessages([$path => ['A redacted value must match an existing stored value at the same position or key.']]);
            }

            return $stored;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::resolve($item, is_array($stored) ? ($stored[$key] ?? null) : null, $path.'.'.$key);
            }
        }

        return $value;
    }
}
