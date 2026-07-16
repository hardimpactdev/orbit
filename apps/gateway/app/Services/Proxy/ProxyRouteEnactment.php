<?php

declare(strict_types=1);

namespace App\Services\Proxy;

final class ProxyRouteEnactment
{
    public const string CONVERGED = 'converged';

    public const string FAILED = 'failed';

    public const string INTENT_ONLY = 'intent_only';

    public const string PARTIAL = 'partial';

    public const string PENDING = 'pending';

    public const string UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{layer: string, node: string, operation: string}>  $plannedOperations
     * @return array<string, mixed>
     */
    public static function pending(array $config, array $plannedOperations): array
    {
        $config['enactment'] = [
            'status' => self::PENDING,
            'planned_operations' => $plannedOperations,
            'completed_operations' => [],
            'failure' => null,
        ];

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{layer: string, node: string, operation: string}  $operation
     * @return array<string, mixed>
     */
    public static function completed(array $config, array $operation): array
    {
        $enactment = self::enactment($config);
        $completed = is_array($enactment['completed_operations'] ?? null)
            ? array_values($enactment['completed_operations'])
            : [];

        if (! in_array($operation, $completed, strict: true)) {
            $completed[] = $operation;
        }

        $enactment['status'] = self::PARTIAL;
        $enactment['completed_operations'] = $completed;
        $enactment['failure'] = null;
        $config['enactment'] = $enactment;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{layer: string, node: string, operation: string}  $failure
     * @return array<string, mixed>
     */
    public static function failed(array $config, array $failure): array
    {
        $enactment = self::enactment($config);
        $completed = is_array($enactment['completed_operations'] ?? null)
            ? array_values($enactment['completed_operations'])
            : [];
        $enactment['status'] = $completed === [] ? self::FAILED : self::PARTIAL;
        $enactment['completed_operations'] = $completed;
        $enactment['failure'] = $failure;
        $config['enactment'] = $enactment;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function converged(array $config): array
    {
        $enactment = self::enactment($config);
        $enactment['status'] = self::CONVERGED;
        $enactment['failure'] = null;
        $config['enactment'] = $enactment;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function intentOnly(array $config): array
    {
        $config['enactment'] = [
            'status' => self::INTENT_ONLY,
            'planned_operations' => [],
            'completed_operations' => [],
            'failure' => null,
        ];

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function status(array $config): string
    {
        $enactment = $config['enactment'] ?? null;
        $status = is_array($enactment) ? $enactment['status'] ?? null : null;

        return is_string($status) && $status !== '' ? $status : self::UNKNOWN;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function enactment(array $config): array
    {
        $enactment = $config['enactment'] ?? null;

        if (! is_array($enactment)) {
            return [];
        }

        $state = [];

        foreach ($enactment as $key => $value) {
            if (! is_string($key)) {
                return [];
            }

            $state[$key] = $value;
        }

        return $state;
    }
}
