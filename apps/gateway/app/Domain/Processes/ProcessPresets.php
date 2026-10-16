<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class ProcessPresets
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            VpDevPreset::NAME,
        ];
    }

    public static function isKnown(string $preset): bool
    {
        return in_array($preset, self::names(), true);
    }

    /** @return list<string> */
    public static function command(string $preset): array
    {
        return match ($preset) {
            VpDevPreset::NAME => VpDevPreset::command(),
            default => throw new \InvalidArgumentException('Unsupported Process preset.'),
        };
    }
}
