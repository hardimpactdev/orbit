<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppWebSocketCredentialsCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'app:websocket credentials';

    #[\Override]
    protected $description = 'Show WebSocket credentials for an app.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('app', InputArgument::OPTIONAL, 'App name or hostname');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $selector = $this->stringArgument('app');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $this->gatewayGet($this->apiAppPath($selector, '/websocket/credentials'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
