<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Services\Convergence\ManagedFile;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;

final readonly class UnattendedUpgradesInstaller implements SecurityInstaller
{
    // @orbit-ssh-lane provisioning-ssh
    private UnattendedUpgradesAptConfig $config;

    public function __construct(?UnattendedUpgradesAptConfig $config = null)
    {
        $this->config = $config ?? new UnattendedUpgradesAptConfig;
    }

    public function installFor(Node $node, ?RemoteShell $provisioningShell = null): InstallReport
    {
        if ($node->isProvisioning()) {
            $result = app(SecurityInstallerTransport::class)->run(
                $node,
                $provisioningShell,
                $this->script(),
                900,
            );

            return new InstallReport(
                successful: $result->successful(),
                summary: $result->successful()
                    ? 'Installed unattended security upgrades.'
                    : 'Failed to install unattended security upgrades.',
                details: [
                    'exit_code' => $result->exitCode,
                    'managed_files' => array_map(
                        static fn (ManagedFile $file): array => [
                            'path' => $file->path,
                            'status' => $result->successful() ? 'changed' : 'failed',
                            'summary' => $result->successful()
                                ? "Applied managed file {$file->path}."
                                : "Failed to apply managed file {$file->path}.",
                        ],
                        $this->managedFiles(),
                    ),
                ],
            );
        }

        $result = app(SecurityInstallerTransport::class)->run(
            $node,
            $provisioningShell,
            $this->packageInstallScript(),
            900,
        );

        $details = [
            'exit_code' => $result->exitCode,
        ];

        if (! $result->successful()) {
            return new InstallReport(
                successful: false,
                summary: 'Failed to install unattended security upgrades.',
                details: $details,
            );
        }

        $managedFiles = [];

        foreach ($this->managedFiles() as $managedFile) {
            $plan = $managedFile->plan($managedFile->probe($node));
            $applyResult = $managedFile->apply($node, $plan);
            $managedFiles[] = [
                'path' => $managedFile->path,
                'status' => $applyResult->status->value,
                'summary' => $applyResult->summary,
            ];

            if (! $applyResult->successful()) {
                return new InstallReport(
                    successful: false,
                    summary: 'Failed to install unattended security upgrades.',
                    details: [
                        ...$details,
                        'managed_files' => $managedFiles,
                    ],
                );
            }
        }

        return new InstallReport(
            successful: true,
            summary: 'Installed unattended security upgrades.',
            details: [
                ...$details,
                'managed_files' => $managedFiles,
            ],
        );
    }

    public function script(): string
    {
        $autoUpgrades = rtrim($this->config->autoUpgrades(), "\n");
        $unattendedUpgrades = rtrim($this->config->unattendedUpgrades(), "\n");

        return <<<SH
            {$this->packageInstallScript()}
            sudo tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null <<'EOF'
            {$autoUpgrades}
            EOF
            sudo tee /etc/apt/apt.conf.d/50unattended-upgrades > /dev/null <<'EOF'
            {$unattendedUpgrades}
            EOF
            SH;
    }

    private function packageInstallScript(): string
    {
        return <<<'SH'
            set -euo pipefail
            if ! command -v unattended-upgrade >/dev/null 2>&1 \
                && ! dpkg-query -W -f='${Status}' unattended-upgrades 2>/dev/null | grep -q 'install ok installed'; then
                sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
                sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq unattended-upgrades
            fi
            SH;
    }

    /**
     * @return list<ManagedFile>
     */
    private function managedFiles(): array
    {
        return [
            new ManagedFile(
                path: '/etc/apt/apt.conf.d/20auto-upgrades',
                content: $this->config->autoUpgrades(),
            ),
            new ManagedFile(
                path: '/etc/apt/apt.conf.d/50unattended-upgrades',
                content: $this->config->unattendedUpgrades(),
            ),
        ];
    }
}
