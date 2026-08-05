<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\App\Concerns\RendersAppAnalyticsBinding;
use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsShowCommand extends AppGatewayCommand
{
    use RendersAppAnalyticsBinding;

    #[\Override]
    protected $name = 'instance:analytics show';

    #[\Override]
    protected $description = 'Show analytics tracking proxy configuration for an instance.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('instance', InputArgument::OPTIONAL, 'Instance selector (app.instance or hostname)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $selector = $this->stringArgument('instance');

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        try {
            $response = $this->gatewayGet($this->apiInstancePath($selector, '/analytics'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->renderAnalyticsBindingWithDashboard($response);

        return self::SUCCESS;
    }
}
