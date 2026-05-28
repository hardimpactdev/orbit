<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Exceptions\GatewayApiException;

final class VpnClientNewCommand extends VpnGatewayCommand
{
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

        try {
            $response = $this->gatewayPost('/api/vpn/clients', [
                ...$this->filledQuery([
                    'name' => $name,
                    'config' => $this->option('config') === true,
                    'totp' => $this->stringOption('totp'),
                ]),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
