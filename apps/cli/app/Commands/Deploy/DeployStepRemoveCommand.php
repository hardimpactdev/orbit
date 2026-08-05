<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;

final class DeployStepRemoveCommand extends DeployGatewayCommand
{
    #[\Override]
    protected $signature = 'deploy:step-remove
        {instance? : Instance selector (app.instance)}
        {step? : Deployment step id or exact title}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a deployment pipeline step from an instance.';

    public function handle(): int
    {
        $instanceSelector = $this->requiredArgument('instance', 'instance', 'Instance and step are required.');

        if (is_int($instanceSelector)) {
            return $instanceSelector;
        }

        $step = $this->requiredArgument('step', 'step', 'Instance and step are required.');

        if (is_int($step)) {
            return $step;
        }

        $consent = $this->confirmRemoval($instanceSelector, $step);

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete('/api/deploy/steps/'.rawurlencode($step), [
                'instance' => $instanceSelector,
                'destructive_consent' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderHumanRemoval($response, $instanceSelector, $step);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderHumanRemoval(array $response, string $instanceSelector, string $step): int
    {
        $stepData = $this->stepData($response);

        $id = $this->stepString($stepData, 'id') ?? $step;
        $title = $this->stepString($stepData, 'title') ?? $step;
        $app = $this->stepString($stepData, 'app');
        $instance = $this->stepString($stepData, 'instance');
        $target = $app !== null && $instance !== null ? "{$app}.{$instance}" : $instanceSelector;
        $order = $this->stepString($stepData, 'order');

        $summary = "Removed deployment step #{$id} '{$title}' from instance '{$target}'.";

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
        $step = is_array($data) ? $data['step'] ?? null : null;

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

    private function confirmRemoval(string $instanceSelector, string $step): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'validation_failed',
                'Use --force to remove this deployment step.',
                ['field' => 'force', 'reason' => 'destructive_consent_required'],
            );
        }

        if ($this->confirm("Remove deployment step '{$step}' from '{$instanceSelector}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            'Use --force to remove this deployment step.',
            ['field' => 'force', 'reason' => 'destructive_consent_required'],
        );
    }
}
