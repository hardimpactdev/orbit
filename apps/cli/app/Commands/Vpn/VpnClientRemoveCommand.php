<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Exceptions\GatewayApiException;

final class VpnClientRemoveCommand extends VpnGatewayCommand
{
    #[\Override]
    protected $signature = 'vpn-client:remove
        {name? : VPN client name}
        {--force : Confirm destructive operation without prompting}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Remove a non-node gateway VPN client through the gateway.';

    public function handle(): int
    {
        $name = $this->promptForArgument('name', 'name', 'VPN client name', 'VPN client name is required.');

        if (is_int($name)) {
            return $name;
        }

        $consent = $this->confirmForce('Use --force to remove this VPN client.');

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete('/api/vpn/clients/'.rawurlencode($name), [
                'force' => true,
                ...$this->totpPayload(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
