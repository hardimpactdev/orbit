<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Node;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GatewayHostAgentConverger
{
    public function __construct(
        private readonly ?GatewayHostAgentConfigWriter $configs = null,
    ) {}

    public function converge(Node $gatewayNode): string
    {
        $configPath = $this->configs()->write($gatewayNode);
        $binary = $this->binaryPath();

        $this->runRequired('test -x '.escapeshellarg($binary), 'verify orbit-agent binary');
        $this->runRequiredWithInput(
            'sudo tee /etc/systemd/system/orbit-agent.service > /dev/null',
            $this->unit($gatewayNode, $binary, $configPath),
            'write orbit-agent systemd unit',
        );
        $this->runRequired('sudo systemctl daemon-reload', 'reload systemd');
        $this->runRequired('sudo systemctl enable orbit-agent', 'enable orbit-agent');
        $this->runRequired('sudo systemctl restart orbit-agent', 'restart orbit-agent');

        $gatewayNode->forceFill(['orbit_agent_capable' => true])->save();

        return $configPath;
    }

    private function unit(Node $gatewayNode, string $binary, string $configPath): string
    {
        $user = $gatewayNode->user ?: 'orbit';
        $httpBind = $this->httpBindAddress($gatewayNode);

        return <<<UNIT
            [Unit]
            Description=Orbit Agent
            After=network-online.target
            Wants=network-online.target

            [Service]
            Type=simple
            User={$user}
            Environment=ORBIT_AGENT_CONFIG={$configPath}
            Environment=ORBIT_AGENT_HTTP_BIND={$httpBind}
            ExecStart={$binary}
            Restart=always
            RestartSec=3

            [Install]
            WantedBy=multi-user.target
            UNIT;
    }

    private function httpBindAddress(Node $gatewayNode): string
    {
        $wireguardAddress = is_string($gatewayNode->wireguard_address)
            ? trim($gatewayNode->wireguard_address)
            : '';

        if ($wireguardAddress === '') {
            throw new RuntimeException(
                "Gateway node [{$gatewayNode->name}] must have a WireGuard address for the Orbit Agent HTTP bind.",
            );
        }

        return "{$wireguardAddress}:9477";
    }

    private function binaryPath(): string
    {
        $binary = config('orbit.agent.binary');

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : '/usr/local/bin/orbit-agent';
    }

    private function runRequired(string $command, string $action): void
    {
        $result = Process::run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Unable to {$action}: ".trim($result->errorOutput() ?: $result->output()));
    }

    private function runRequiredWithInput(string $command, string $input, string $action): void
    {
        $result = Process::input($input)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Unable to {$action}: ".trim($result->errorOutput() ?: $result->output()));
    }

    private function configs(): GatewayHostAgentConfigWriter
    {
        return $this->configs ?? app(GatewayHostAgentConfigWriter::class);
    }
}
