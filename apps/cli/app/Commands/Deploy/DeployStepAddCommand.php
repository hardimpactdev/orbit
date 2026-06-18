<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;

final class DeployStepAddCommand extends DeployGatewayCommand
{
    private const int DEFAULT_TIMEOUT = 600;

    #[\Override]
    protected $signature = 'deploy:step-add
        {app? : Production app name or domain}
        {deploy_command? : Shell command to run}
        {--title= : Display title}
        {--order= : Positive insertion order}
        {--timeout=600 : Timeout in seconds}
        {--retention= : Optional release retention metadata}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a deployment pipeline step for a production app.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'App and command are required.');

        if (is_int($app)) {
            return $app;
        }

        $command = $this->requiredArgument('deploy_command', 'command', 'App and command are required.');

        if (is_int($command)) {
            return $command;
        }

        $numericFailure = $this->validateNumericOptions();

        if ($numericFailure !== null) {
            return $numericFailure;
        }

        try {
            $response = $this->gatewayPost('/api/deploy/steps', $this->payload($app, $command));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderHumanStep($response, $app);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderHumanStep(array $response, string $app): int
    {
        $step = $this->stepData($response);

        $id = $this->stepString($step, 'id');
        $title = $this->stepString($step, 'title');
        $appName = $this->stepString($step, 'app') ?? $app;

        $this->line("Added deployment step #{$id} '{$title}' to app '{$appName}'.");

        $command = $this->stepString($step, 'command');

        if ($command !== null) {
            $this->line("Command: {$command} (order {$this->stepDetail($step, 'order')}, retention {$this->retentionLabel($step)}).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function stepData(array $response): array
    {
        $data = $response['success']['data'] ?? null;
        $step = is_array($data) ? ($data['step'] ?? null) : null;

        return is_array($step) ? $step : [];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepString(array $step, string $key): ?string
    {
        $value = $step[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepDetail(array $step, string $key): string
    {
        return $this->stepString($step, $key) ?? 'default';
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function retentionLabel(array $step): string
    {
        return $this->stepString($step, 'retention') ?? 'unlimited';
    }

    private function validateNumericOptions(): ?int
    {
        foreach (['timeout', 'order', 'retention'] as $field) {
            if ($this->positiveIntOption($field, $field === 'timeout' ? self::DEFAULT_TIMEOUT : null) === false) {
                return $this->failValidation($field, "Invalid value for --{$field}: must be a positive integer.");
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $app, string $command): array
    {
        return $this->filledPayload([
            'app' => $app,
            'command' => $command,
            'title' => $this->stringOption('title'),
            'order' => $this->positiveIntOption('order'),
            'timeout' => $this->positiveIntOption('timeout', self::DEFAULT_TIMEOUT),
            'retention' => $this->positiveIntOption('retention'),
        ]);
    }
}
