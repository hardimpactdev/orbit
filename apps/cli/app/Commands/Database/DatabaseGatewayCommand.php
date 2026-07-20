<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;

abstract class DatabaseGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function requiredArgument(string $argument, string $field, string $message): string|int
    {
        $value = $this->stringArgument($argument);

        if ($value === null) {
            return $this->failValidation($field, $message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function filledPayload(array $payload): array
    {
        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionFieldPayload(): array
    {
        return $this->filledPayload([
            'driver' => $this->stringOption('driver'),
            'node' => $this->stringOption('node'),
            'host' => $this->stringOption('host'),
            'port' => $this->stringOption('port'),
            'database' => $this->stringOption('database'),
            'path' => $this->stringOption('path'),
            'username' => $this->stringOption('username'),
            'password' => $this->stringOption('password'),
        ]);
    }

    /**
     * @return array<string, mixed>|int
     */
    protected function targetPayload(): array|int
    {
        $instance = $this->stringOption('instance');
        $workspace = $this->stringOption('workspace');

        if ($instance !== null && $workspace !== null) {
            return $this->failValidation('scope', 'Invalid scope: --instance and --workspace cannot be combined.');
        }

        if ($instance === null && $workspace === null) {
            return $this->failValidation('scope', 'Either --instance or --workspace is required.');
        }

        return $this->filledPayload([
            'instance' => $instance,
            'workspace' => $workspace,
            'env_prefix' => $this->stringOption('env-prefix') ?? 'DB',
        ]);
    }
}
