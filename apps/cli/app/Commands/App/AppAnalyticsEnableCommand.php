<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsEnableCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'app:analytics enable';

    #[\Override]
    protected $description = 'Enable analytics tracking proxy support for an app.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('app', InputArgument::OPTIONAL, 'App name or hostname');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Public analytics tracking host to bind');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $selector = $this->stringArgument('app');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $this->gatewayPost($this->apiAppPath($selector, '/analytics/enable'), [
                'public_hosts' => $this->publicHosts(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    /**
     * @return list<string>
     */
    private function publicHosts(): array
    {
        $hosts = $this->option('host');

        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_filter($hosts, is_string(...)));
    }
}
