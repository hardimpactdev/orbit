<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class AppSetupStepRemoveCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance-setup-step:remove
        {instance? : Instance selector (app.instance or hostname)}
        {--step= : Setup step id}
        {--force : Skip confirmation}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove an instance setup step.';

    public function handle(): int
    {
        $app = $this->resolveInstanceSelector();

        if ($app === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        $step = $this->resolveStepId();

        if ($step === null) {
            return $this->renderFailure('validation_failed', 'Step ID must be a positive integer.', [
                'field' => 'step',
                'reason' => 'must_be_positive_integer',
            ]);
        }

        if (! $this->hasDestructiveConsent()) {
            return $this->failValidation(
                'force',
                'This is a destructive operation. Use --force or confirm the prompt.',
            );
        }

        try {
            $response = $this->gatewayDelete($this->apiInstancePath($app, "/setup-steps/{$step}"), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderStepRemoved($step, $response);
    }

    private function resolveInstanceSelector(): ?string
    {
        return $this->stringArgument('instance') ?? $this->instanceFromOrbitMarker();
    }

    private function resolveStepId(): ?int
    {
        $value = $this->option('step');

        if (($value === null || $value === '') && $this->isInteractiveInput()) {
            $value = text(label: 'Step ID', required: true);
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function hasDestructiveConsent(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->isInteractiveInput()) {
            return false;
        }

        return confirm(label: 'Remove this instance setup step?', default: false);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderStepRemoved(int $step, array $response): int
    {
        $data = $this->successData($response);
        $removed = is_array($data['step'] ?? null) ? $data['step'] : [];
        $stepId = is_int($removed['id'] ?? null) ? $removed['id'] : $step;
        $instance = is_string($removed['instance'] ?? null) && $removed['instance'] !== ''
            ? $removed['instance']
            : '';

        $this->line("✓ Removed setup step {$stepId} from instance '{$instance}'.");

        if ($this->remainingStepCount($response) === 0) {
            $this->line("Instance '{$instance}' now has no setup steps.");
        } else {
            $this->line('Remaining steps renumbered.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function remainingStepCount(array $response): ?int
    {
        $meta = $response['success']['meta'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        $count = $meta['remaining_step_count'] ?? null;

        return is_int($count) ? $count : null;
    }
}
