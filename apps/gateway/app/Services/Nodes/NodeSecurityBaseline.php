<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SecurityInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use App\Services\Security\UnattendedUpgradesInstaller;
use App\Services\Support\GatewayActionResult;

final readonly class NodeSecurityBaseline
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private RemoteShell $shell,
        private HomeDirectoryLockdownInstaller $homeDirectoryLockdown,
        private SshdHardenedInstaller $sshdHardened,
        private SysctlBaselineInstaller $sysctlBaseline,
        private UnattendedUpgradesInstaller $unattendedUpgrades,
        private PublicSshDenyInstaller $publicSshDeny,
    ) {}

    public function apply(Node $node): ?GatewayActionResult
    {
        /** @var array<string, SecurityInstaller> $installers */
        $installers = [
            'home' => $this->homeDirectoryLockdown,
            'sshd' => $this->sshdHardened,
            'sysctl' => $this->sysctlBaseline,
            'unattended_upgrades' => $this->unattendedUpgrades,
            'public_ssh_deny' => $this->publicSshDeny,
        ];

        foreach ($installers as $step => $installer) {
            $report = $installer->installFor($node, $this->shell);

            if ($report->successful) {
                continue;
            }

            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Host '{$node->host}' could not finalize the node security baseline.",
                meta: [
                    'host' => $node->host,
                    'step' => $step,
                    'exit_code' => $report->details['exit_code'] ?? null,
                ],
            );
        }

        return null;
    }
}
