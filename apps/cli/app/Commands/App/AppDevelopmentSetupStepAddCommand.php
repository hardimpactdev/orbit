<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\text;

final class AppDevelopmentSetupStepAddCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:add {app? : App name} {--command= : Shell command} {--before= : Step id} {--after= : Step id} {--timeout=600 : Timeout in seconds} {--json : Output JSON}';
    #[\Override]
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
        $input = AppDevelopmentSetupStepAddInput::fromOptions(
            command: $command,
            timeout: $this->option('timeout'),
            before: $this->option('before'),
            after: $this->option('after'),
        );
        if (isset($input['error'])) {
            return $this->failValidation($input['error']['field'], $input['error']['message']);
        }
        $values = $input['values'] ?? [];
        try {
            $response = $this->gatewayPost(
                $this->apiProjectPath($app, '/development-setup-steps'),
                $values,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }
        $data = $this->successData($response);
        $step = is_array($data['step'] ?? null) ? $data['step'] : [];
        $this->line('✓ Added development setup default '.self::field($step, 'id')." for app '{$app}'.");
        $this->line('Command: '.self::field($step, 'command'));
        $this->line('Order: '.self::field($step, 'order'));
        $this->line('Timeout: '.self::field($step, 'timeout_seconds').'s');

        return self::SUCCESS;
    }

    private static function field(mixed $step, string $key): string
    {
        if (! is_array($step) || ! is_scalar($step[$key] ?? null)) {
            return '';
        }

        return (string) $step[$key];
    }
}
