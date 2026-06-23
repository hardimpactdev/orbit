<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;

abstract class DeployGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

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
            if ($this->allowsInteractiveInput()) {
                $answer = $this->ask($this->promptLabel($field));

                if (is_string($answer) && trim($answer) !== '') {
                    return trim($answer);
                }
            }

            return $this->failValidation($field, $message);
        }

        return $value;
    }

    private function promptLabel(string $field): string
    {
        return str($field)->replace('_', ' ')->title()->toString();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function filledPayload(array $payload): array
    {
        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function positiveIntOption(string $key, ?int $default = null): int|false|null
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }
}
