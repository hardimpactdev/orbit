<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppAnalyticsShowCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'app:analytics show';

    #[\Override]
    protected $description = 'Show analytics tracking proxy configuration for an app.';

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
            $response = $this->gatewayGet($this->apiAppPath($selector, '/analytics'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $binding = $this->bindingData($response);

        $this->line('binding:');
        $this->line('  app: '.$this->stringField($binding, 'app'));
        $this->line('  enabled: '.($this->boolField($binding, 'enabled') ? 'true' : 'false'));
        $this->line('  internal_host: '.$this->stringField($binding, 'internal_host'));
        $this->line('  dashboard_url: '.$this->stringField($binding, 'dashboard_url'));
        $this->renderHostList($this->listField($binding, 'public_hosts'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function bindingData(array $response): array
    {
        $binding = $this->successData($response)['binding'] ?? null;

        return is_array($binding) ? $binding : [];
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function stringField(array $binding, string $key): string
    {
        $value = $binding[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function boolField(array $binding, string $key): bool
    {
        return (bool) ($binding[$key] ?? false);
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return list<string>
     */
    private function listField(array $binding, string $key): array
    {
        $value = $binding[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @param  list<string>  $hosts
     */
    private function renderHostList(array $hosts): void
    {
        if ($hosts === []) {
            $this->line('  public_hosts: []');

            return;
        }

        $this->line('  public_hosts:');

        foreach ($hosts as $host) {
            $this->line('    - '.$host);
        }
    }
}
