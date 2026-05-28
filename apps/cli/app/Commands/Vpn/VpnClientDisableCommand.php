<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

final class VpnClientDisableCommand extends VpnClientEnableCommand
{
    protected $signature = 'vpn-client:disable
        {name? : VPN client name}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    protected $description = 'Disable a non-node gateway VPN client through the gateway.';

    #[\Override]
    public function handle(): int
    {
        return $this->handleToggle('disable');
    }
}
