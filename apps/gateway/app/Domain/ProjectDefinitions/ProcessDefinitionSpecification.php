<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
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
        self::assertSupportedMarkers($changes);

        $spec = array_key_exists('runtime', $changes) && $changes['runtime'] !== ($stored['runtime'] ?? null)
            ? [] : $stored;
        foreach ($changes as $field => $value) {
            if ($value === null && ! in_array($field, self::Collections, true)) {
                unset($spec[$field]);
            } else {
                $spec[$field] = $value;
            }
        }

        return $spec;
    }

    public static function assertSupportedMarkers(#[SensitiveParameter] mixed $value, string $path = 'spec'): void
    {
        if (is_string($value) && str_contains($value, CommandActivityInputSanitizer::REDACTED)) {
            throw ValidationException::withMessages([
                $path => ['Redacted values cannot be submitted. Send only the fields you want to change; omit the redacted ones.'],
            ]);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($path === 'spec.environment' && $item === CommandActivityInputSanitizer::REDACTED) {
                    continue;
                }
                self::assertSupportedMarkers($item, $path.'.'.$key);
            }
        }
    }
}
