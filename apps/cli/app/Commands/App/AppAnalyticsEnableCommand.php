<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\App\Concerns\RendersAppAnalyticsBinding;
use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsEnableCommand extends AppGatewayCommand
{
    use WithStepTree;
    use RendersAppAnalyticsBinding;

    #[\Override]
    protected $name = 'instance:analytics enable';

    #[\Override]
    protected $description = 'Enable analytics tracking proxy support for an instance.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('instance', InputArgument::OPTIONAL, 'Instance selector (app.instance or hostname)');
        $this->addOption(
            'host',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Public analytics tracking host to bind',
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $selector = $this->stringArgument('instance');

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        $payload = ['public_hosts' => $this->publicHosts()];

        if ($this->wantsJson()) {
            try {
                $response = $this->enableAnalytics($selector, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        $response = [];
        $outcome = $this->runStepOperation(
            'Enabling Instance Analytics',
            [
                ['label' => 'Validate instance and analytics prerequisites'],
                ['label' => 'Register public tracking routes'],
                ['label' => 'Apply router tracking routes'],
                ['label' => 'Apply ingress TLS and tracking routes'],
            ],
            work: function () use ($selector, $payload, &$response): array {
                try {
                    return $response = $this->enableAnalytics($selector, $payload);
                } catch (GatewayApiException $exception) {
                    throw new RuntimeException(
                        $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                        previous: $exception,
                    );
                }
            },
            doneFooter: "Analytics enabled for instance '{$selector}'",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderAnalyticsBindingWithDashboard($response);
        $this->renderAnalyticsEnableGuidance($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enableAnalytics(string $selector, array $payload): array
    {
        return $this->gatewayPost($this->apiInstancePath($selector, '/analytics/enable'), $payload);
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
