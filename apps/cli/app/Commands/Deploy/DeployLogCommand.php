<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;

final class DeployLogCommand extends DeployGatewayCommand
{
    #[\Override]
    protected $signature = 'deploy:log
        {instance? : Instance selector (app.instance)}
        {run? : Deployment run id}
        {--step= : Deployment run step id}
        {--lines=500 : Lines per captured stream}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show stored deployment output for an instance run.';

    public function handle(): int
    {
        $instanceSelector = $this->requiredArgument('instance', 'instance', 'Instance is required.');

        if (is_int($instanceSelector)) {
            return $instanceSelector;
        }

        $run = $this->positiveIntArgument('run', 'Run');

        if ($run === null) {
            return $this->failValidation('run', 'A positive run id is required.');
        }

        $step = $this->positiveIntOption('step');
        $lines = $this->positiveIntOption('lines', 500);

        foreach (['step' => $step, 'lines' => $lines] as $field => $value) {
            if ($value === false) {
                return $this->failValidation($field, "Invalid value for --{$field}: must be a positive integer.");
            }
        }

        try {
            $response = $this->gatewayGet('/api/deploy/log/'.$run, $this->filledQuery([
                'instance' => $instanceSelector,
                'step' => $step,
                'lines' => $lines,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->renderCapturedOutput($response);

        return self::SUCCESS;
    }

    private function positiveIntArgument(string $key, string $label): ?int
    {
        $value = $this->stringArgument($key);

        if ($value === null && ! $this->wantsJson() && $this->input->isInteractive()) {
            $answer = $this->ask($label);

            if (is_string($answer) && trim($answer) !== '') {
                $value = trim($answer);
            }
        }

        if ($value === null || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCapturedOutput(array $response): void
    {
        $data = $this->successData($response);
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];

        if ($steps === []) {
            $this->line('No captured deployment output.');

            return;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $title = is_string($step['title'] ?? null) && trim($step['title']) !== ''
                ? trim($step['title'])
                : 'Deployment step';
            $status = is_string($step['status'] ?? null) && trim($step['status']) !== ''
                ? trim($step['status'])
                : 'unknown';
            $output = is_array($step['output'] ?? null) ? $step['output'] : [];
            $stdout = is_string($output['stdout'] ?? null) ? trim($output['stdout']) : '';
            $stderr = is_string($output['stderr'] ?? null) ? trim($output['stderr']) : '';

            $this->line("{$title} ({$status}):");

            if ($stdout !== '') {
                $this->line('stdout:');
                $this->line($stdout);
            }

            if ($stderr !== '') {
                $this->line('stderr:');
                $this->line($stderr);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
