<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use Orbit\Core\Progress\ProgressEventType;

final class DeployRunCommand extends DeployGatewayCommand
{
    #[\Override]
    protected $signature = 'deploy:run
        {app? : Production app name or domain}
        {--detach : Start and return after the run is durable}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Run the deployment pipeline for a production app.';

    public function handle(): int
    {
        $app = $this->requiredArgument('app', 'app', 'App is required.');

        if (is_int($app)) {
            return $app;
        }

        return $this->streamProgress(
            '/api/deploy/run',
            [
                'app' => $app,
                'detach' => $this->option('detach') === true,
            ],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
