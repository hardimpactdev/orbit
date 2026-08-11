<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Support\GatewayActionResult;
use App\Services\Vpn\WgEasyAddressReservationProbe;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;

final readonly class ClientNodeEnroller
{
    private const string DEFAULT_RUNTIME_USER = 'orbit';

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private WireGuardKeyGenerator $wireGuardKeyGenerator,
        private DnsmasqReconciler $dnsmasqReconciler,
        private NodeRoleAssignments $roleAssignments,
        private WgEasyAddressReservationProbe $addressReservationProbe,
    ) {}

    public function enroll(
        string $name,
        bool $operator,
        NodeCreationInput $input,
    ): GatewayActionResult {
        $forbiddenInput = $this->forbiddenIdentityInput($input);

        if ($forbiddenInput !== null) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Client identities do not use workload or SSH/bootstrap-only input.',
                meta: ['field' => $forbiddenInput],
            );
        }

        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && ! $existing->isOperator()) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different role.",
                meta: [
                    'name' => $name,
                    'existing_role' => $existing->displayRole(),
                    'requested_role' => $operator ? 'operator' : 'client',
                ],
            );
        }

        $wireguardAddress =
            $existing instanceof Node && is_string($existing->wireguard_address) && $existing->wireguard_address !== ''
                ? $existing->wireguard_address
                : $this->nextWireguardAddress();
        $gateway = $this->roleAssignments->activeGatewayNodeQuery()->first();

        if (! $gateway instanceof Node) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'step' => 'gateway_identity',
                    'error' => 'No active gateway node record exists.',
                ],
            );
        }

        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway->id)->first();

        if (! $gatewayPeer instanceof WireGuardPeer) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Gateway WireGuard peer material is missing locally.',
                meta: [
                    'step' => 'gateway_wireguard_identity',
                    'node' => $gateway->name,
                ],
            );
        }

        try {
            $keys = $this->wireGuardKeyGenerator->generateKeyPair();
        } catch (RuntimeException $exception) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $tld = $input->stringOption('tld');

        if ($tld === null || ! NodeTld::isValid($tld)) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Every node identity requires an explicit valid non-reserved TLD.',
                meta: ['field' => 'tld', 'name' => $name],
            );
        }

        if ($existing instanceof Node && $existing->tld !== $tld) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different TLD.",
                meta: ['field' => 'tld', 'existing' => $existing->tld, 'requested' => $tld],
            );
        }

        $tldConflict = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where('tld', $tld);

        if ($existing instanceof Node) {
            $tldConflict->whereKeyNot($existing->id);
        }

        if ($tldConflict->exists()) {
            return GatewayActionResult::error(
                code: 'node.tld_in_use',
                message: "Node TLD '{$tld}' is already assigned to another node.",
                meta: ['field' => 'tld', 'value' => $tld],
            );
        }

        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'tld' => $tld,
                'platform' => 'unknown',
                'host' => $wireguardAddress,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $this->gatewayEndpoint(),
                'user' => self::DEFAULT_RUNTIME_USER,
                'orbit_path' => '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
                'status' => 'active',
            ],
        );

        $peer = WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $gatewayPeer->public_key,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gateway->gateway_endpoint ?? $gateway->host,
        );

        $this->dnsmasqReconciler->reconcileRecords();

        return GatewayActionResult::success([
            'result' => ['action' => 'enrolled'],
            'node' => [
                'name' => $name,
                'tld' => $tld,
                'platform' => 'unknown',
                'addresses' => ['wireguard' => $wireguardAddress],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'wireguard',
                'host' => null,
                'status' => 'enrolled',
            ],
            'wireguard' => ['config' => $wireguardConfig],
            'next_steps' => [
                'Install the WireGuard configuration on the '.($operator ? 'operator node' : 'client').'.',
                'Join the Orbit WireGuard network.',
                'Run `orbit gateway:add` on the '.($operator ? 'operator node' : 'client').'.',
            ],
        ]);
    }

    /** @mago-expect lint:excessive-parameter-list */
    private function controlWireGuardConfig(
        string $controlPrivateKey,
        string $controlWireguardAddress,
        string $gatewayPublicKey,
        string $gatewayWireguardAddress,
        string $gatewayEndpoint,
        ?string $preSharedKey = null,
        ?string $allowedIps = null,
    ): string {
        $lines = [
            '[Interface]',
            "PrivateKey = {$controlPrivateKey}",
            "Address = {$controlWireguardAddress}/24",
            '',
            '[Peer]',
            "PublicKey = {$gatewayPublicKey}",
        ];

        if ($preSharedKey !== null) {
            $lines[] = "PresharedKey = {$preSharedKey}";
        }

        return implode("\n", [
            ...$lines,
            'AllowedIPs = '.($allowedIps ?? "{$gatewayWireguardAddress}/32"),
            "Endpoint = {$gatewayEndpoint}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    private function gatewayEndpoint(): ?string
    {
        $gateway = $this->roleAssignments->activeGatewayNodeQuery()->first();

        if (! $gateway instanceof Node) {
            return null;
        }

        return $gateway->wireguard_address ?? $gateway->gateway_endpoint ?? $gateway->host;
    }

    private function forbiddenIdentityInput(NodeCreationInput $input): ?string
    {
        foreach ([
            'host',
            'operator-name',
            'operator-tld',
            'ingress',
            'valkey-node',
            'postgres-node',
            'postgres-process',
            'clickhouse-node',
            's3-data-path',
            'gateway-endpoint',
            'host-key-fingerprint',
        ] as $option) {
            if ($input->stringOption($option) !== null) {
                return $option;
            }
        }

        foreach (['agent-tool', 'grant-to', 'grant-from'] as $option) {
            if ($input->arrayOption($option) !== []) {
                return $option;
            }
        }

        foreach ([
            'self-grant',
            'self-grant-permissions',
            'grant-to-preset',
            'grant-to-permissions',
            'grant-from-preset',
            'grant-from-permissions',
        ] as $option) {
            if ($input->stringOption($option) !== null) {
                return $option;
            }
        }

        return $input->optionWasSupplied('user') ? 'user' : null;
    }

    private function nextWireguardAddress(): string
    {
        $used = $this->usedWireguardAddresses();

        for ($octet = 3; $octet <= 254; $octet++) {
            $candidate = "10.6.0.{$octet}";

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No available WireGuard addresses remain in 10.6.0.0/24.');
    }

    /** @return list<string> */
    private function usedWireguardAddresses(): array
    {
        /** @var list<string> $nodeAddresses */
        $nodeAddresses = Node::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->all();
        /** @var list<string> $allowedIpsValues */
        $allowedIpsValues = WireGuardPeer::query()
            ->whereNotNull('allowed_ips')
            ->pluck('allowed_ips')
            ->all();
        $peerAddresses = [];

        foreach ($allowedIpsValues as $allowedIps) {
            array_push($peerAddresses, ...$this->wireguardAddressesFromAllowedIps($allowedIps));
        }

        return array_values(array_unique(array_merge(
            $nodeAddresses,
            $peerAddresses,
            $this->addressReservationProbe->addresses(),
        )));
    }

    /** @return list<string> */
    private function wireguardAddressesFromAllowedIps(string $allowedIps): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $allowedIp): string => trim(explode('/', trim($allowedIp), 2)[0]),
                explode(',', $allowedIps),
            ),
            fn (string $address): bool => $address !== '',
        ));
    }
}
