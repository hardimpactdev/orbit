<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Exceptions\GatewayApiException;

final class DeployRunCommand extends DeployGatewayCommand
{
    protected $signature = 'deploy:run
        {app? : Production app name or domain}
        {--detach : Start and return after the run is durable}
        {--json : Output JSON}';

    protected $description = 'Run the deployment pipeline for a production app.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'App is required.');

        if (is_int($app)) {
            return $app;
        }

        try {
            $response = $this->gatewayPost('/api/deploy/run', [
                'app' => $app,
                'detach' => $this->option('detach') === true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
