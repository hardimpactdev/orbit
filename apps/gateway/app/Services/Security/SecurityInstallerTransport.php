<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Tools\ToolScriptDispatcher;
use RuntimeException;

final readonly class SecurityInstallerTransport
{
    public function __construct(
        private ToolScriptDispatcher $scripts,
    ) {}

    public function run(Node $node, ?RemoteShell $provisioningShell, string $script, int $timeout): RemoteShellResult
    {
        if ($node->isProvisioning()) {
            if (! $provisioningShell instanceof RemoteShell) {
                throw new RuntimeException('Provisioning security convergence requires its SSH bootstrap shell.');
            }

            // @orbit-ssh-lane provisioning-ssh
            return $provisioningShell->run($node, $script, [
                'timeout' => $timeout,
                'throw' => false,
            ]);
        }

        if (! $node->isAgentEligible()) {
            throw new RuntimeException('Security convergence requires an Agent-eligible node.');
        }

        return $this->scripts->run($node, 'orbit-security', 'reconfigure', $script);
    }
}
