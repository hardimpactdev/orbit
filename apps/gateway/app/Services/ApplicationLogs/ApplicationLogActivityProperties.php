<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use Illuminate\Http\Request;

final readonly class ApplicationLogActivityProperties
{
    /**
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    public static function forInstance(
        Request $request,
        string $selector,
        ?array $target,
        string $mode,
        int $lines,
        ?string $outcome = null,
    ): array {
        return array_filter(
            [
                'target' => $target,
                'selector' => $selector,
                'node' => self::optionalString($request, 'node'),
                'mode' => $mode,
                'lines' => $lines,
                'outcome' => $outcome,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    public static function forWorkspace(
        Request $request,
        string $workspace,
        ?array $target,
        string $mode,
        int $lines,
        ?string $outcome = null,
    ): array {
        return array_filter(
            [
                'target' => $target,
                'workspace' => $workspace,
                'instance' => self::optionalString($request, 'instance'),
                'selector' => $workspace,
                'node' => self::optionalString($request, 'node'),
                'mode' => $mode,
                'lines' => $lines,
                'outcome' => $outcome,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private static function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
