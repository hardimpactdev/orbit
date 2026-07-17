<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\App\Concerns\RendersAppAnalyticsBinding;
use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsDisableCommand extends AppGatewayCommand
{
    use RendersAppAnalyticsBinding;
    use WithStepTree;

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

        if ($this->wantsJson()) {
            try {
                $response = $this->disableAnalytics($selector);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        $response = [];
        $outcome = $this->runStepOperation(
            'Disabling App Analytics',
            [
                ['label' => 'Resolve app analytics binding'],
                ['label' => 'Remove ingress tracking routes'],
                ['label' => 'Remove router tracking routes'],
                ['label' => 'Disable analytics binding'],
            ],
            work: function () use ($selector, &$response): array {
                try {
                    return $response = $this->disableAnalytics($selector);
                } catch (GatewayApiException $exception) {
                    throw new RuntimeException(
                        $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                        previous: $exception,
                    );
                }
            },
            doneFooter: "Analytics disabled for app '{$selector}'",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderAnalyticsBinding($response);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function disableAnalytics(string $selector): array
    {
        return $this->gatewayPost($this->apiAppPath($selector, '/analytics/disable'));
    }
}
