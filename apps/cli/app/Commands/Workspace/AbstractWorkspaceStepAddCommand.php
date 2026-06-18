<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\text;

abstract class AbstractWorkspaceStepAddCommand extends WorkspaceGatewayCommand
{
    abstract protected function phase(): string;

    abstract protected function phaseLabel(): string;

    public function handle(): int
    {
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
            return $this->renderFailure('workspace.invalid_position', 'Both insertion flags cannot be supplied.', [
                'before' => $before,
                'after' => $after,
            ]);
        }

        $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();

        try {
            $response = $this->gatewayPost("/api/workspaces/steps/{$this->phase()}", $this->filledQuery([
                'app' => $app,
                'path' => $app === null ? $this->hostCwd() : null,
                'command' => $command,
                'timeout' => $timeout,
                'before' => is_int($before) ? $before : null,
                'after' => is_int($after) ? $after : null,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderStepAdded($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderStepAdded(array $response): int
    {
        $data = $this->successData($response);
        $step = is_array($data['step'] ?? null) ? $data['step'] : [];
        $app = is_string($step['app'] ?? null) && $step['app'] !== '' ? $step['app'] : '';

        $this->line(ucfirst($this->phaseLabel())." step added for app '{$app}'.");
        $this->line('ID: '.$this->scalarField($step, 'id'));
        $this->line('Command: '.$this->scalarField($step, 'command'));
        $this->line('Order: '.$this->scalarField($step, 'order'));
        $this->line('Timeout: '.$this->scalarField($step, 'timeout_seconds').' seconds');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function scalarField(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
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
}
