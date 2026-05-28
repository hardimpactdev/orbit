<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DeployStepListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'deploy:step-list
        {app : Production app name or domain}
        {--json}';

    #[\Override]
    protected $description = 'List deployment pipeline steps for a production app.';

    public function handle(): int
    {
        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->renderFailure(
                'validation_failed',
                'The app argument is required.',
                ['field' => 'app'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/deploy/steps', $this->filledQuery([
                'app' => $app,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
