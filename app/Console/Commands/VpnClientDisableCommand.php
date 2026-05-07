<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('vpn-client:disable
    {name : VPN client name}
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('Disable a non-node gateway VPN client')]
final class VpnClientDisableCommand extends VpnClientEnableCommand
{
    #[\Override]
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->handleToggle(enabled: false);
        } finally {
            $this->finishActivityLog();
        }
    }
}
