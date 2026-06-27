<?php

declare(strict_types=1);

use Orbit\Core\Updates\UnattendedUpgradesAptConfig;

describe(UnattendedUpgradesAptConfig::class, function (): void {
    it('returns the exact auto-upgrades apt periodic config bytes', function (): void {
        $config = new UnattendedUpgradesAptConfig;

        expect($config->autoUpgrades())->toBe(<<<'CONF'
            APT::Periodic::Update-Package-Lists "1";
            APT::Periodic::Unattended-Upgrade "1";

            CONF);
    });

    it('returns the exact auto-upgrades config sha256 digest', function (): void {
        $config = new UnattendedUpgradesAptConfig;

        expect($config->autoUpgradesSha256())
            ->toBe('b81a3a01864d5ed7f8d023f3fa86d65087ad6b85706d399dc229a60b1c784807');
    });

    it('returns the exact unattended-upgrades apt config bytes', function (): void {
        $config = new UnattendedUpgradesAptConfig;

        expect($config->unattendedUpgrades())->toBe(<<<'CONF'
            Unattended-Upgrade::Allowed-Origins {
                    "${distro_id}:${distro_codename}-security";
                    "${distro_id}ESMApps:${distro_codename}-apps-security";
                    "${distro_id}ESM:${distro_codename}-infra-security";
            };
            Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
            Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
            Unattended-Upgrade::Remove-Unused-Dependencies "true";
            Unattended-Upgrade::Automatic-Reboot "false";

            CONF);
    });

    it('returns the exact unattended-upgrades config sha256 digest', function (): void {
        $config = new UnattendedUpgradesAptConfig;

        expect($config->unattendedUpgradesSha256())
            ->toBe('37c280a2ecefbb03622c36b7434ee79ce7c1f6219c1c352b0d56aed23e4cf040');
    });
});
