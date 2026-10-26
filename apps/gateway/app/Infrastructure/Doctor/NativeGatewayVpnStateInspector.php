<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\GatewayVpnInspectionData;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

/**
 * Reads the `vpn` Node's interface state and the digests of its hub and VPN DNS record files through
 * `wireguard.inspect-hub`, and compares them with what the Gateway renders now.
 */
final readonly class NativeGatewayVpnStateInspector implements GatewayVpnStateInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private VpnConfigurationRepository $configuration,
        private WireGuardServerConfigRenderer $serverRenderer,
        private DevelopmentDnsConfigRenderer $dnsRenderer,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(NodeRole $role): GatewayVpnInspectionData
    {
        try {
            $server = $this->server($role);
            $vpn = $this->configuration->forPeer($server);
            $serverConfig = $this->serverRenderer->render(
                $vpn,
                Node::query()->whereNotNull('wireguard_public_key')->get(),
            );
            $dnsConfig = $this->dnsRenderer->render();
            $state = $this->agents->run($server, 'wireguard.inspect-hub', [], $this->deadline->cap(30.0));
            if (! is_bool($state['active'] ?? null)) {
                throw new DoctorInspectionException;
            }

            return new GatewayVpnInspectionData(
                $state['active'],
                $this->matches($state, 'config_sha256', $serverConfig),
                $this->matches($state, 'dns_records_sha256', $dnsConfig),
            );
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    private function server(NodeRole $role): Node
    {
        $role->loadMissing('node');
        $node = $role->node;

        if ($role->role !== RoleName::Vpn || $role->node_id !== $node->id || $node->platform !== 'linux') {
            throw new DoctorInspectionException;
        }

        return $node;
    }

    /**
     * Whether the file's digest is the expected content's. A missing file is a mismatch.
     *
     * @param  array<string, mixed>  $state
     */
    private function matches(array $state, string $field, string $expected): bool
    {
        if (! array_key_exists($field, $state)) {
            throw new DoctorInspectionException;
        }
        $digest = $state[$field];

        return match (true) {
            $digest === null => false,
            is_string($digest) => hash_equals($digest, hash('sha256', $expected)),
            default => throw new DoctorInspectionException,
        };
    }
}
