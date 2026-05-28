<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;

abstract class VpnGatewayCommand extends GatewayCommand
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

    protected function promptForArgument(string $argument, string $field, string $label, string $message): string|int
    {
        $value = $this->stringArgument($argument);

        if ($value !== null) {
            return $value;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation($field, $message, ['reason' => 'missing']);
        }

        $answer = $this->ask($label);

        if (is_string($answer) && trim($answer) !== '') {
            return trim($answer);
        }

        return $this->failValidation($field, $message, ['reason' => 'missing']);
    }

    protected function confirmForce(string $message): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', $message, [
                'reason' => 'destructive_consent_required',
            ]);
        }

        if ($this->confirm($message, default: false)) {
            return null;
        }

        return $this->failValidation('force', 'Operation cancelled.', [
            'reason' => 'destructive_consent_required',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function totpPayload(): array
    {
        return $this->filledQuery(['totp' => $this->stringOption('totp')]);
    }
}
