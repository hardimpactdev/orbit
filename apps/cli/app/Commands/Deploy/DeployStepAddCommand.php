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

        return $this->renderSuccess($response);
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
