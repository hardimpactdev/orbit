<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

abstract class AbstractWorkspaceStepRemoveCommand extends WorkspaceGatewayCommand
{
    abstract protected function phase(): string;

    abstract protected function phaseLabel(): string;

    public function handle(): int
    {
        $step = $this->resolveStepId();

        if ($step === null) {
            return $this->renderFailure('validation_failed', 'Step ID must be a positive integer.', [
                'field' => 'step',
                'reason' => 'must_be_positive_integer',
            ]);
        }

        if (! $this->hasDestructiveConsent()) {
            return $this->failValidation('force', 'This is a destructive operation. Use --force or confirm the prompt.');
        }

        $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();

        try {
            $response = $this->gatewayDelete($this->pathWithQuery(
                "/api/workspaces/steps/{$this->phase()}/{$step}",
                [
                    'app' => $app,
                    'path' => $app === null ? $this->hostCwd() : null,
                ],
            ), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
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

        return confirm(label: 'Remove this workspace step?', default: false);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
