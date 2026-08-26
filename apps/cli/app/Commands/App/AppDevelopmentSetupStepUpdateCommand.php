<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

// mago:ignore cyclomatic-complexity -- command input contract intentionally validates multiple independent fields
final class AppDevelopmentSetupStepUpdateCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:update {app? : App name} {step? : Step id} {--command=} {--timeout=} {--before=} {--after=} {--json : Output JSON}';
    protected $description = 'Update an app development setup default.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }
        $step = $this->positiveArgument('step');
        if ($step === null) {
            return $this->failValidation('step', 'Step must be a positive integer.');
        }
        $payload = $this->filledQuery([
            'command' => $this->stringOption('command'),
            'timeout' => $this->positiveOption('timeout'),
            'before' => $this->positiveOption('before'),
            'after' => $this->positiveOption('after'),
        ]);
        foreach (['timeout', 'before', 'after'] as $field) {
            if ($this->option($field) !== null && ! array_key_exists($field, $payload)) {
                return $this->failValidation($field, "The --{$field} option must be a positive integer.");
            }
        }
        if (($payload['before'] ?? null) !== null && ($payload['after'] ?? null) !== null) {
            return $this->failValidation('before', 'Both insertion flags cannot be supplied.');
        }
        if ($payload === []) {
            return $this->failValidation('change', 'At least one change is required.');
        }
        try {
            $response = $this->gatewayPatch($this->apiProjectPath($app, "/development-setup-steps/{$step}"), $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }
        $this->line("✓ Updated development setup default {$step} for app '{$app}'.");

        return self::SUCCESS;
    }

    private function positiveArgument(string $name): ?int
    {
        $value = $this->stringArgument($name);

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
