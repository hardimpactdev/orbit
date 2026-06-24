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
        $service = self::optionalString($runtimeConfig, 'service');

        if ($service !== null) {
            return $service;
        }

        return self::optionalString($runtimeConfig, 'definition');
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    public static function serviceFromLabels(array $labels): ?string
    {
        $service = self::optionalString($labels, 'orbit.process.service');

        if ($service !== null) {
            return $service;
        }

        return self::optionalString($labels, 'orbit.process.definition');
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
