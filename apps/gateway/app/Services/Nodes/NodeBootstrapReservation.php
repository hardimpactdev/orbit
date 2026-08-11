<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Support\GatewayActionResult;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\WgEasyAddressReservationProbe;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class NodeBootstrapReservation
{
    private const int WIREGUARD_RESERVATION_ATTEMPTS = 3;

    private const string WIREGUARD_RESERVATION_LOCK = 'orbit:node-bootstrap:wireguard-reservation';

    private const string DEFAULT_RUNTIME_USER = 'orbit';

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private NodeRegistryWriter $registryWriter,
        private WireGuardKeyGenerator $wireGuardKeyGenerator,
        private NodeBootstrapBundleBuilder $bundleBuilder,
        private VpnDnsSwarmInstaller $vpnInstaller,
        private WgEasyAddressReservationProbe $addressReservationProbe,
        private NodeRoleAssignments $roleAssignments,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     */
    public function prepare(
        string $name,
        WorkloadNodeProvisioningInput $inputs,
        Node $caller,
        array $request,
    ): GatewayActionResult {
        for ($attempt = 1; $attempt <= self::WIREGUARD_RESERVATION_ATTEMPTS; $attempt++) {
            try {
                /** @var GatewayActionResult */
                return Cache::lock(self::WIREGUARD_RESERVATION_LOCK, 120)->block(
                    30,
                    fn (): GatewayActionResult => $this->prepareWithReservationLock(
                        $name,
                        $inputs,
                        $caller,
                        $request,
                    ),
                );
            } catch (QueryException $exception) {
                if (
                    $attempt === self::WIREGUARD_RESERVATION_ATTEMPTS
                    || ! $this->isWireguardAddressCollision($exception)
                ) {
                    throw $exception;
                }
            } catch (LockTimeoutException) {
                return GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'WireGuard identity reservation is busy; retry node bootstrap.',
                    meta: [
                        'node' => $name,
                        'step' => 'wireguard_allocation',
                    ],
                );
            }
        }

        throw new RuntimeException('WireGuard identity reservation attempts were exhausted.');
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function prepareWithReservationLock(
        string $name,
        WorkloadNodeProvisioningInput $inputs,
        Node $caller,
        array $request,
    ): GatewayActionResult {
        $existing = Node::query()->where('name', $name)->first();
        $bootstrap = $existing instanceof Node
            ? NodeBootstrap::query()->where('node_id', $existing->id)->first()
            : null;

        if (
            $existing instanceof Node
            && ! $this->pendingBootstrapIsCompatible(
                node: $existing,
                bootstrap: $bootstrap,
                caller: $caller,
                request: $request,
                inputs: $inputs,
            )
        ) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with incompatible bootstrap state.",
                meta: ['name' => $name],
            );
        }

        $tld = $inputs->tld;

        if (
            is_string($tld)
            && Node::query()->where('tld', $tld)->where('name', '!=', $name)->exists()
        ) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "Node TLD '{$tld}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $tld,
                ],
            );
        }

        $wireguardAddress = $existing?->wireguard_address;

        if (! is_string($wireguardAddress) || trim($wireguardAddress) === '') {
            $wireguardAddress = $this->resolveProvisionedNodeWireguardAddress();
        }

        if ($wireguardAddress instanceof GatewayActionResult) {
            return $wireguardAddress;
        }

        $gateway = $this->roleAssignments->activeGatewayNodeQuery()->first();

        if (! $gateway instanceof Node) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'node' => $name,
                    'step' => 'gateway_identity',
                ],
            );
        }

        $gatewayEndpoint = $inputs->gatewayEndpoint ?? $this->gatewayPublicEndpoint($gateway);

        if ($gatewayEndpoint === null) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Gateway public WireGuard endpoint is missing locally.',
                meta: [
                    'node' => $gateway->name,
                    'step' => 'gateway_wireguard_endpoint',
                ],
            );
        }

        DB::beginTransaction();

        try {
            $node = $existing ?? $this->registryWriter->writeNodeIdentity(
                name: $name,
                tld: $tld,
                platform: $inputs->platform,
                host: $inputs->host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: self::DEFAULT_RUNTIME_USER,
                orbitPath: '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
                status: NodeStatus::Provisioning,
                architecture: $inputs->architecture,
            );
            $node->forceFill([
                'platform' => $inputs->platform,
                'architecture' => $inputs->architecture,
                'managed' => true,
                'status' => NodeStatus::Provisioning,
            ])->save();

            $peer = $this->ensureProvisionedNodeWireGuardPeer($node, $wireguardAddress);

            if ($peer instanceof GatewayActionResult) {
                DB::rollBack();

                return $peer;
            }

            $bootstrap ??= NodeBootstrap::query()->create([
                'node_id' => $node->id,
                'initiating_node_id' => $caller->id,
                'request' => $request,
                'status' => 'pending',
                'last_error' => null,
            ]);

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $wireguardServerPublicKey = $this->configureGatewayWireGuardServerPeer($node, $peer, $wireguardAddress);

        if ($wireguardServerPublicKey instanceof GatewayActionResult) {
            return $wireguardServerPublicKey;
        }

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $wireguardServerPublicKey,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gatewayEndpoint,
            preSharedKey: $peer->pre_shared_key,
            allowedIps: '10.6.0.0/24',
        );

        try {
            $script = $this->bundleBuilder->build(
                node: $node,
                gateway: $gateway,
                peer: $peer,
                wireguardConfig: $wireguardConfig,
            );
        } catch (Throwable $exception) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' bootstrap bundle could not be rendered.",
                meta: [
                    'node' => $name,
                    'step' => 'bootstrap_bundle',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $bootstrap->forceFill([
            'status' => 'pending',
            'last_error' => null,
        ])->save();

        return GatewayActionResult::success([
            'bootstrap' => [
                'id' => $bootstrap->id,
                'status' => 'pending',
                'host' => $inputs->host,
                'user' => $inputs->sshUser ?? 'root',
                'wireguard_address' => $wireguardAddress,
                'script' => $script,
            ],
        ]);
    }

    private function ensureProvisionedNodeWireGuardPeer(
        Node $node,
        string $wireguardAddress,
    ): WireGuardPeer|GatewayActionResult {
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        if ($peer instanceof WireGuardPeer && $peer->private_key !== '') {
            if (! is_string($peer->pre_shared_key) || $peer->pre_shared_key === '') {
                $peer->pre_shared_key = $this->generatePreSharedKey();
            }

            $peer->allowed_ips = "{$wireguardAddress}/32";
            $peer->save();

            return $peer;
        }

        try {
            $keys = $this->wireGuardKeyGenerator->generateKeyPair();
            $preSharedKey = $this->generatePreSharedKey();
        } catch (RuntimeException $exception) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        return WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'pre_shared_key' => $preSharedKey,
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );
    }

    private function configureGatewayWireGuardServerPeer(
        Node $node,
        WireGuardPeer $peer,
        string $wireguardAddress,
    ): string|GatewayActionResult {
        if ($peer->public_key === '' || $peer->pre_shared_key === null || $peer->pre_shared_key === '') {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but WireGuard peer material is incomplete.",
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                ],
            );
        }

        try {
            $this->vpnInstaller->configurePeers([
                [
                    'name' => $node->name,
                    'private_key' => $peer->private_key,
                    'public_key' => $peer->public_key,
                    'pre_shared_key' => $peer->pre_shared_key,
                    'address' => $wireguardAddress,
                ],
            ]);

            return $this->vpnInstaller->publicKey();
        } catch (RuntimeException $exception) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Gateway could not install WireGuard peer for node '{$node->name}'.",
                meta: [
                    'node' => $node->name,
                    'step' => 'gateway_wireguard_peer',
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    private function gatewayPublicEndpoint(Node $gateway): ?string
    {
        /** @var NodeRoleAssignment|null $vpnRole */
        $vpnRole = $gateway
            ->roleAssignments()
            ->where('role', NodeRoleName::Vpn->value)
            ->first();

        $settings = $vpnRole?->settings;

        if (
            is_array($settings)
            && is_string($settings['public_endpoint'] ?? null)
            && $settings['public_endpoint'] !== ''
        ) {
            return $settings['public_endpoint'];
        }

        foreach ([$gateway->gateway_endpoint, $gateway->host] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function pendingBootstrapIsCompatible(
        Node $node,
        ?NodeBootstrap $bootstrap,
        Node $caller,
        array $request,
        WorkloadNodeProvisioningInput $inputs,
    ): bool {
        return (
            $node->isProvisioning()
            && $node->host === $inputs->host
            && $node->tld === $inputs->tld
            && $node->platform === $inputs->platform
            && $node->architecture === $inputs->architecture
            && $bootstrap instanceof NodeBootstrap
            && $bootstrap->initiating_node_id === $caller->id
            && $bootstrap->request === $request
            && $bootstrap->status === 'pending'
        );
    }

    private function isWireguardAddressCollision(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (
            str_contains($message, 'wireguard_address')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'))
        );
    }

    private function resolveProvisionedNodeWireguardAddress(): string|GatewayActionResult
    {
        $reservedAddress = $this->e2eReservedWireguardAddress();

        if ($reservedAddress === null) {
            return $this->nextWireguardAddress();
        }

        if (! $this->isManagedWireguardAddress($reservedAddress)) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Prepared topology WireGuard address must be in the managed 10.6.0.3-10.6.0.254 range.',
                meta: ['field' => 'wireguard_address'],
            );
        }

        if (in_array($reservedAddress, $this->usedWireguardAddresses(), true)) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "WireGuard address '{$reservedAddress}' is already assigned.",
                meta: [
                    'field' => 'wireguard_address',
                    'value' => $reservedAddress,
                ],
            );
        }

        return $reservedAddress;
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

    /**
     * @return list<string>
     */
    private function usedWireguardAddresses(): array
    {
        /** @var list<string> $used */
        $used = Node::query()
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

        $wgEasyAddresses = $this->addressReservationProbe->addresses();

        return array_values(array_unique(array_merge($used, $peerAddresses, $wgEasyAddresses)));
    }

    /**
     * @return list<string>
     */
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

    private function e2eReservedWireguardAddress(): ?string
    {
        $e2e = getenv('ORBIT_E2E');

        if (! is_string($e2e) || $e2e === '' || $e2e === '0') {
            return null;
        }

        $address = getenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return trim($address);
    }

    private function isManagedWireguardAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $parts = array_map(intval(...), explode('.', $address));

        return $parts[0] === 10 && $parts[1] === 6 && $parts[2] === 0 && $parts[3] >= 3 && $parts[3] <= 254;
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

    private function generatePreSharedKey(): string
    {
        try {
            return base64_encode(random_bytes(32));
        } catch (Throwable $exception) {
            throw new RuntimeException('WireGuard pre-shared key generation failed.', previous: $exception);
        }
    }
}
