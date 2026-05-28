<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;

abstract class ProcessGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    private const array RESTART_POLICIES = ['never', 'on_failure', 'always'];

    private const array CRASH_NOTIFICATIONS = ['none', 'agent_ide'];

    private const array RUNTIMES = ['docker', 'supervisor'];

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function appContext(): ?string
    {
        return $this->stringOption('app') ?? $this->appFromOrbitMarker();
    }

    protected function validateProcessName(?string $name): ?int
    {
        if ($name === null) {
            return $this->failValidation('name', 'The process name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->failValidation('name', 'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', [
                'value' => $name,
            ]);
        }

        return null;
    }

    protected function validateRestartPolicy(?string $value): ?int
    {
        if ($value === null || in_array($value, self::RESTART_POLICIES, true)) {
            return null;
        }

        return $this->failValidation('restart_policy', 'Invalid restart policy.', [
            'value' => $value,
            'allowed' => self::RESTART_POLICIES,
        ]);
    }

    protected function validateCrashNotification(?string $value): ?int
    {
        if ($value === null || in_array($value, self::CRASH_NOTIFICATIONS, true)) {
            return null;
        }

        return $this->failValidation('crash_notification', 'Invalid crash notification policy.', [
            'value' => $value,
            'allowed' => self::CRASH_NOTIFICATIONS,
        ]);
    }

    protected function validateRuntime(?string $value): ?int
    {
        if ($value === null || in_array($value, self::RUNTIMES, true)) {
            return null;
        }

        return $this->failValidation('runtime', 'Invalid process runtime.', [
            'value' => $value,
            'allowed' => self::RUNTIMES,
        ]);
    }
}
