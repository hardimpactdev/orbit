<?php

declare(strict_types=1);

namespace Orbit\Core\Updates;

final class UnattendedUpgradesAptConfig
{
    public function autoUpgrades(): string
    {
        return "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n";
    }

    public function unattendedUpgrades(): string
    {
        return <<<'CONF'
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";

CONF;
    }

    public function autoUpgradesSha256(): string
    {
        return hash('sha256', $this->autoUpgrades());
    }

    public function unattendedUpgradesSha256(): string
    {
        return hash('sha256', $this->unattendedUpgrades());
    }
}
