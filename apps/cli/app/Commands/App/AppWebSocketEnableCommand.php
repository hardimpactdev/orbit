<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppWebSocketEnableCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'app:websocket enable';

    #[\Override]
    protected $description = 'Enable WebSocket support for an app.';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('app', InputArgument::OPTIONAL, 'App name or hostname');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Public WebSocket host to bind');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    public function handle(): int
    {
        $selector = $this->stringArgument('app');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $this->gatewayPost($this->apiAppPath($selector, '/websocket/enable'), [
                'public_hosts' => $this->publicHosts(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $binding = $this->bindingData($response);

        $this->line('binding:');
        $this->line('  app: '.$this->stringField($binding, 'app'));
        $this->line('  internal_host: '.$this->stringField($binding, 'internal_host'));
        $this->renderList('public_hosts', $this->listField($binding, 'public_hosts'));
        $this->renderList('allowed_origins', $this->listField($binding, 'allowed_origins'));

        return self::SUCCESS;
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
     * @param  list<string>  $items
     */
    private function renderList(string $label, array $items): void
    {
        if ($items === []) {
            $this->line("  {$label}: []");

            return;
        }

        $this->line("  {$label}:");

        foreach ($items as $item) {
            $this->line('    - '.$item);
        }
    }
}
