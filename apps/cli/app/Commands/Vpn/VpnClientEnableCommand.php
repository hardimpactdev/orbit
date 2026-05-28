<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Exceptions\GatewayApiException;

class VpnClientEnableCommand extends VpnGatewayCommand
{
    #[\Override]
    protected $signature = 'vpn-client:enable
        {name? : VPN client name}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Enable a non-node gateway VPN client through the gateway.';

    public function handle(): int
    {
        return $this->handleToggle('enable');
    }

    protected function handleToggle(string $action): int
    {
        $name = $this->promptForArgument('name', 'name', 'VPN client name', 'VPN client name is required.');

        if (is_int($name)) {
            return $name;
        }

        try {
            $response = $this->gatewayPost(
                '/api/vpn/clients/'.rawurlencode($name)."/{$action}",
                $this->totpPayload(),
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
