<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DeployHistoryCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'deploy:history
        {app : Production app name or domain}
        {--limit= : Number of runs to return (default 50, hard cap 500)}
        {--json}';

    #[\Override]
    protected $description = 'List deployment runs for a production app.';

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
            $response = $this->gatewayGet('/api/deploy/history', $this->filledQuery([
                'app' => $app,
                'limit' => $this->stringOption('limit'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
