<?php

declare(strict_types=1);

namespace App\Services\Processes;

final class ProcessRuntimeServiceMetadata
{
    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    public static function service(array $runtimeConfig): ?string
    {
        return self::optionalString($runtimeConfig, 'service');
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    public static function serviceFromLabels(array $labels): ?string
    {
        return self::optionalString($labels, 'orbit.process.service');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
