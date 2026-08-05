<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppWebSocketDisableCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'instance:websocket disable';

    #[\Override]
    protected $description = 'Disable WebSocket support for an instance.';

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
            $response = $this->gatewayPost($this->apiInstancePath($selector, '/websocket/disable'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $binding = $this->bindingData($response);

        $this->line('binding:');
        $this->line('  instance: '.$this->stringField($binding, 'instance'));
        $this->line('  internal_host: '.$this->stringField($binding, 'internal_host'));
        $this->renderList('public_hosts', $this->listField($binding, 'public_hosts'));
        $this->renderList('allowed_origins', $this->listField($binding, 'allowed_origins'));

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
