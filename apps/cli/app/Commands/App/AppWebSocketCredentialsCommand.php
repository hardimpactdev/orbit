<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class AppWebSocketCredentialsCommand extends AppGatewayCommand
{
    #[\Override]
    protected $name = 'instance:websocket credentials';

    #[\Override]
    protected $description = 'Show WebSocket credentials for an instance.';

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
            $response = $this->gatewayGet($this->apiInstancePath($selector, '/websocket/credentials'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $credentials = $this->credentialsData($response);

        $this->line('credentials:');
        $this->line('  instance: '.$this->stringField($credentials, 'instance'));
        $this->line('  internal_host: '.$this->stringField($credentials, 'internal_host'));
        $this->renderList('public_hosts', $this->listField($credentials, 'public_hosts'));
        $this->renderList('allowed_origins', $this->listField($credentials, 'allowed_origins'));
        $this->line('  reverb_app_id: '.$this->stringField($credentials, 'reverb_app_id'));
        $this->line('  reverb_app_key: '.$this->stringField($credentials, 'reverb_app_key'));
        $this->line('  reverb_app_secret: '.$this->stringField($credentials, 'reverb_app_secret'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function credentialsData(array $response): array
    {
        $credentials = $this->successData($response)['credentials'] ?? null;

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function stringField(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<string>
     */
    private function listField(array $credentials, string $key): array
    {
        $value = $credentials[$key] ?? null;

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
