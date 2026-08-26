<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\text;

// mago:ignore cyclomatic-complexity -- command input contract intentionally validates multiple independent fields
final class AppDevelopmentSetupStepAddCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:add {app? : App name} {--command= : Shell command} {--before= : Step id} {--after= : Step id} {--timeout=600 : Timeout in seconds} {--json : Output JSON}';
    protected $description = 'Add an app development setup default.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }
        $command = $this->stringOption('command');
        if ($command === null && ! $this->wantsJson() && $this->input->isInteractive()) {
            $command = trim(text(label: 'Command', required: true));
        }
        if ($command === null) {
            return $this->failValidation('command', 'Command is required.');
        }
        $timeout = $this->positiveInt('timeout', 600);
        if ($timeout === null) {
            return $this->failValidation('timeout', 'Timeout must be a positive integer.');
        }
        $before = $this->positiveInt('before');
        $after = $this->positiveInt('after');
        if ($this->option('before') !== null && $before === null) {
            return $this->failValidation('before', 'The --before option must be a positive integer.');
        }
        if ($this->option('after') !== null && $after === null) {
            return $this->failValidation('after', 'The --after option must be a positive integer.');
        }
        if ($before !== null && $after !== null) {
            return $this->failValidation('before', 'Both insertion flags cannot be supplied.');
        }
        try {
            $response = $this->gatewayPost(
                $this->apiProjectPath($app, '/development-setup-steps'),
                $this->filledQuery([
                    'command' => $command,
                    'timeout' => $timeout,
                    'before' => $before,
                    'after' => $after,
                ]),
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }
        $step = $this->successData($response)['step'] ?? [];
        $this->line('✓ Added development setup default '.self::field($step, 'id')." for app '{$app}'.");
        $this->line('Command: '.self::field($step, 'command'));

        return self::SUCCESS;
    }

    private function positiveInt(string $name, ?int $default = null): ?int
    {
        $value = $this->option($name);
        if (($value === null || $value === '') && $default !== null) {
            return $default;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function field(mixed $step, string $key): string
    {
        $value = is_array($step) ? $step[$key] ?? '' : '';

        return is_scalar($value) ? (string) $value : '';
    }
}
