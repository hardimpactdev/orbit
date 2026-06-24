<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class VpnClientNewCommand extends VpnGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'vpn-client:new
        {name? : VPN client name}
        {--config : Include the generated WireGuard config}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Create a non-node gateway VPN client through the gateway.';

    public function handle(): int
    {
        $name = $this->promptForArgument('name', 'name', 'VPN client name', 'VPN client name is required.');

        if (is_int($name)) {
            return $name;
        }

        $includeConfig = $this->option('config') === true;

        if ($this->wantsJson()) {
            try {
                $response = $this->createClient($name, $includeConfig);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderCreationTree($name, $includeConfig);
    }

    private function renderCreationTree(string $name, bool $includeConfig): int
    {
        $response = [];

        $phases = [
            ['label' => 'Resolve VPN runtime'],
            ['label' => 'Authenticate VPN backend'],
            ['label' => 'Create VPN client'],
        ];

        if ($includeConfig) {
            $phases[] = ['label' => 'Render client config'];
        }

        $outcome = $this->runStepOperation(
            'Creating VPN client',
            $phases,
            work: function () use ($name, $includeConfig, &$response): array {
                return $response = $this->createClientForHuman($name, $includeConfig);
            },
            doneFooter: function () use (&$response): string {
                $client = $this->client($response);
                $clientName = is_string($client['name'] ?? null) ? $client['name'] : '';

                return "VPN client `{$clientName}` created";
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderCreationDetails($response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function createClient(string $name, bool $includeConfig): array
    {
        return $this->gatewayPost('/api/vpn/clients', [
            ...$this->filledQuery([
                'name' => $name,
                'config' => $includeConfig,
                'totp' => $this->stringOption('totp'),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createClientForHuman(string $name, bool $includeConfig): array
    {
        try {
            return $this->createClient($name, $includeConfig);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCreationDetails(array $response): void
    {
        $client = $this->client($response);

        if (is_string($client['address'] ?? null) && $client['address'] !== '') {
            $this->line("Address: {$client['address']}");
        }

        if (is_string($client['config'] ?? null) && $client['config'] !== '') {
            $this->line('');
            $this->line($client['config']);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function client(array $response): array
    {
        $data = $response['success']['data'] ?? null;
        $client = is_array($data) ? $data['client'] ?? null : null;

        return is_array($client) ? $client : [];
    }
}
