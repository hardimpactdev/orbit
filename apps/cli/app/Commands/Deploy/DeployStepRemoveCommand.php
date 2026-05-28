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

        return $this->renderSuccess($response);
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
