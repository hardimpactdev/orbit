<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class VpnClientListCommand extends VpnGatewayCommand
{
    #[\Override]
    protected $signature = 'vpn-client:list
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'List gateway VPN backend clients through the gateway.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/vpn/clients', $this->totpPayload());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $clients = $this->clientsFromGatewayResponse($response);

        if ($clients === []) {
            $this->line('No VPN clients configured.');

            return self::SUCCESS;
        }

        table(
            headers: ['NAME', 'ADDRESS', 'ENABLED', 'KIND', 'LATEST HANDSHAKE'],
            rows: array_map(fn (array $client): array => [
                $this->clientString($client, 'name'),
                $this->clientString($client, 'address'),
                $this->enabledLabel($client),
                $this->clientString($client, 'kind'),
                $this->latestHandshakeLabel($client),
            ], $clients),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function clientsFromGatewayResponse(array $response): array
    {
        $clients = $response['success']['data']['clients'] ?? null;

        if (! is_array($clients)) {
            return [];
        }

        return array_values(array_filter($clients, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function enabledLabel(array $client): string
    {
        $enabled = $client['enabled'] ?? null;

        if (! is_bool($enabled)) {
            return '—';
        }

        return $enabled ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function latestHandshakeLabel(array $client): string
    {
        $handshake = $client['latest_handshake_at'] ?? null;

        if (is_scalar($handshake) && (string) $handshake !== '') {
            return (string) $handshake;
        }

        return 'never';
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function clientString(array $client, string $key): string
    {
        $value = $client[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
