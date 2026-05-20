<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

final class UnattendedUpgradesInstaller implements SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport
    {
        $result = $shell->run($node, $this->script(), [
            'timeout' => 900,
            'throw' => false,
        ]);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Installed unattended security upgrades.'
                : 'Failed to install unattended security upgrades.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function script(): string
    {
        return <<<'SH'
set -euo pipefail
sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq unattended-upgrades
sudo tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
sudo tee /etc/apt/apt.conf.d/50unattended-upgrades > /dev/null <<'EOF'
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF
SH;
    }
}
