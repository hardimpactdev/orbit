<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\RemoteShell\RemoteHostExecutor;
use RuntimeException;

final readonly class GatewayHostAgentServiceRestarter
{
    /**
     * @param  RemoteShell|null  $remoteShellForTests
     */
    public function __construct(
        private RemoteHostExecutor $remoteHostExecutor,
        private mixed $remoteShellForTests = null,
    ) {}

    /**
     * @param  array{unit_name: string, exec_start: string, config_path: string, http_bind: string, user: string}  $agentService
     */
    public function restart(OperationRun $operationRun, Node $gatewayNode, array $agentService): void
    {
        $options = [
            'timeout' => 60,
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationRun->id,
            ],
            'throw' => true,
        ];

        if ($this->remoteShellForTests instanceof RemoteShell) {
            $result = $this->remoteShellForTests->run(
                node: $gatewayNode,
                script: $this->script($this->systemdServiceName($agentService['unit_name'])),
                options: $options,
            );
        } else {
            $result = $this->remoteHostExecutor->run(
                node: $gatewayNode,
                script: $this->script($this->systemdServiceName($agentService['unit_name'])),
                options: [
                    ...$options,
                    'force_remote_host' => true,
                ],
            );
        }

        if (! $result->successful()) {
            throw new RuntimeException('Gateway host Orbit Agent service restart failed: '.$result->output());
        }
    }

    private function systemdServiceName(string $unitName): string
    {
        return str_ends_with($unitName, '.service') ? $unitName : "{$unitName}.service";
    }

    private function script(string $unitName): string
    {
        $unitName = escapeshellarg($unitName);

        return <<<BASH
            set -euo pipefail

            unit_name={$unitName}

            run_privileged() {
                if "\$@"; then
                    return
                fi

                sudo -n "\$@"
            }

            systemctl_bin="\$(command -v systemctl || true)"

            if [ -z "\$systemctl_bin" ]; then
                echo skip_gateway_agent_restart_no_systemctl
                exit 0
            fi

            if ! "\$systemctl_bin" status "\$unit_name" >/dev/null 2>&1 && ! "\$systemctl_bin" is-enabled "\$unit_name" >/dev/null 2>&1; then
                echo skip_gateway_agent_restart_no_unit
                exit 0
            fi

            echo restart_gateway_agent_unit
            run_privileged "\$systemctl_bin" restart "\$unit_name"
            "\$systemctl_bin" is-active --quiet "\$unit_name"
            echo gateway_agent_unit_active
            BASH;
    }
}
