<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsDisableCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'app:analytics disable';

    #[\Override]
    protected $description = 'Disable analytics tracking proxy support for an app.';

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
            $response = $this->gatewayPost($this->apiAppPath($selector, '/analytics/disable'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
