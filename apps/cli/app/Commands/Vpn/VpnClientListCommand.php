<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Exceptions\GatewayApiException;

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

        return $this->renderSuccess($response);
    }
}
