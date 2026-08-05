<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\text;

final class AppSetupStepAddCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance-setup-step:add
        {instance? : Instance selector (app.instance or hostname)}
        {--command= : Shell command to run during instance setup}
        {--before= : Insert before this setup step id}
        {--after= : Insert after this setup step id}
        {--timeout=600 : Timeout in seconds}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add an instance setup step.';

    public function handle(): int
    {
        $app = $this->resolveInstanceSelector();

        if ($app === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        $command = $this->resolveStepCommand();

        if ($command === null) {
            return $this->failValidation('command', 'Command is required.');
        }

        $timeout = $this->parseTimeout();

        if ($timeout === null) {
            return $this->renderFailure('validation_failed', 'Timeout must be a positive integer.', [
                'field' => 'timeout',
                'reason' => 'must_be_positive_integer',
            ]);
        }

        $before = $this->parseOptionalPositiveInt('before');
        $after = $this->parseOptionalPositiveInt('after');

        if ($before === false) {
            return $this->failValidation('before', 'The --before option must be a positive integer.');
        }

        if ($after === false) {
            return $this->failValidation('after', 'The --after option must be a positive integer.');
        }

        if (is_int($before) && is_int($after)) {
            return $this->renderFailure('instance_setup.invalid_position', 'Both insertion flags cannot be supplied.', [
                'before' => $before,
                'after' => $after,
            ]);
        }

        try {
            $response = $this->gatewayPost(
                $this->apiInstancePath($app, '/setup-steps'),
                $this->filledQuery([
                    'command' => $command,
                    'timeout' => $timeout,
                    'before' => is_int($before) ? $before : null,
                    'after' => is_int($after) ? $after : null,
                ]),
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $step = $this->step($response);
        $this->line("Setup step added for instance '{$this->scalarField($step, 'instance')}'.");
        $this->line('ID: '.$this->scalarField($step, 'id'));
        $this->line('Command: '.$this->scalarField($step, 'command'));
        $this->line('Order: '.$this->scalarField($step, 'order'));
        $this->line('Timeout: '.$this->scalarField($step, 'timeout_seconds').' seconds');

        return self::SUCCESS;
    }

    private function resolveInstanceSelector(): ?string
    {
        return $this->stringArgument('instance') ?? $this->instanceFromOrbitMarker();
    }

    private function resolveStepCommand(): ?string
    {
        $command = $this->stringOption('command');

        if ($command !== null) {
            return $command;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Command', required: true));
        }

        return null;
    }

    private function parseTimeout(): ?int
    {
        $value = $this->option('timeout');

        if ($value === null || $value === '') {
            return 600;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function parseOptionalPositiveInt(string $option): int|false|null
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function step(array $response): array
    {
        $data = $this->successData($response);

        return is_array($data['step'] ?? null) ? $data['step'] : [];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function scalarField(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
