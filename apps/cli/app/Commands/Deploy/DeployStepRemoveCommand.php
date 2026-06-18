<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;

final class DeployStepRemoveCommand extends DeployGatewayCommand
{
    #[\Override]
    protected $signature = 'deploy:step-remove
        {app? : Production app name or domain}
        {step? : Deployment step id or exact title}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a deployment pipeline step from a production app.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'App and step are required.');

        if (is_int($app)) {
            return $app;
        }

        $step = $this->requiredArgument('step', 'step', 'App and step are required.');

        if (is_int($step)) {
            return $step;
        }

        $consent = $this->confirmRemoval($app, $step);

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete('/api/deploy/steps/'.rawurlencode($step), [
                'app' => $app,
                'destructive_consent' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderHumanRemoval($response, $app, $step);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderHumanRemoval(array $response, string $app, string $step): int
    {
        $stepData = $this->stepData($response);

        $id = $this->stepString($stepData, 'id') ?? $step;
        $title = $this->stepString($stepData, 'title') ?? $step;
        $appName = $this->stepString($stepData, 'app') ?? $app;
        $order = $this->stepString($stepData, 'order');

        $summary = "Removed deployment step #{$id} '{$title}' from app '{$appName}'.";

        if ($order !== null) {
            $summary .= " Previous order {$order}.";
        }

        $this->line($summary);

        if ($this->historyPreserved($response)) {
            $this->line('Deployment history preserved.');
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
     * @param  array<string, mixed>  $response
     */
    private function historyPreserved(array $response): bool
    {
        return ($response['success']['meta']['history_preserved'] ?? null) === true;
    }

    private function confirmRemoval(string $app, string $step): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'destructive_consent_required',
                'Use --force to remove this deployment step.',
                ['field' => 'force'],
            );
        }

        if ($this->confirm("Remove deployment step '{$step}' from '{$app}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'destructive_consent_required',
            'Use --force to remove this deployment step.',
            ['field' => 'force'],
        );
    }
}
