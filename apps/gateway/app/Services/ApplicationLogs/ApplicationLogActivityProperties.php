<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use Illuminate\Http\Request;

final readonly class ApplicationLogActivityProperties
{
    /**
     * @param  array{
     *     request: Request,
     *     selector: string,
     *     target?: array<string, mixed>|null,
     *     mode: string,
     *     lines: int,
     *     outcome?: string|null
     * }  $context
     * @return array<string, mixed>
     */
    public static function forInstance(array $context): array
    {
        return self::filter([
            'target' => $context['target'] ?? null,
            'selector' => $context['selector'],
            'node' => self::optionalString($context['request'], 'node'),
            'mode' => $context['mode'],
            'lines' => $context['lines'],
            'outcome' => $context['outcome'] ?? null,
        ]);
    }

    /**
     * @param  array{
     *     request: Request,
     *     workspace: string,
     *     target?: array<string, mixed>|null,
     *     mode: string,
     *     lines: int,
     *     outcome?: string|null
     * }  $context
     * @return array<string, mixed>
     */
    public static function forWorkspace(array $context): array
    {
        $request = $context['request'];

        return self::filter([
            'target' => $context['target'] ?? null,
            'workspace' => $context['workspace'],
            'instance' => self::optionalString($request, 'instance'),
            'selector' => $context['workspace'],
            'node' => self::optionalString($request, 'node'),
            'mode' => $context['mode'],
            'lines' => $context['lines'],
            'outcome' => $context['outcome'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function filter(array $properties): array
    {
        return array_filter(
            $properties,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private static function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
