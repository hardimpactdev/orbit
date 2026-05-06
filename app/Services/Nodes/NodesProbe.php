<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Platform\PlatformDetector;
use RuntimeException;
use Throwable;

final readonly class NodesProbe
{
    private const array SUPPORTED_ROLES = ['control', 'gateway', 'app'];

    private const array SUPPORTED_AGENT_IDE_ADAPTERS = ['none', 'opencode', 'polyscope'];

    public function __construct(
        private ?PlatformDetector $platformDetector = null,
    ) {}

    public function key(): string
    {
        return 'nodes';
    }

    public function label(): string
    {
        return 'Nodes';
    }

    public function introspect(Node $node): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Node $node, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($node));
        $drift = array_merge($drift, $this->checkLocalRole($node));
        $drift = array_merge($drift, $this->checkLocalDefault($node));
        $drift = array_merge($drift, $this->checkAgentIdeDefault($node));
        $drift = array_merge($drift, $this->checkAccessGrants($node));
        $drift = array_merge($drift, $this->checkWireguardIdentity($node));
        $drift = array_merge($drift, $this->checkPlatformReality($node));
        $drift = array_merge($drift, $this->checkSshReachability($node));
        $drift = array_merge($drift, $this->checkGatewayRuntime($node));
        $drift = array_merge($drift, $this->checkAppRuntime($node));
        $drift = array_merge($drift, $this->checkDevelopmentTld($node));
        $drift = array_merge($drift, $this->checkCliPhpDefault($node));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Node $node): array
    {
        if (
            ! is_string($node->role)
            || $node->role === ''
            || ! is_string($node->status)
            || $node->status === ''
            || ! is_string($node->platform)
            || $node->platform === ''
            || ! is_string($node->wireguard_address)
            || $node->wireguard_address === ''
            || ($node->role === 'app' && (! is_string($node->environment) || $node->environment === ''))
            || ! is_string($node->host)
            || $node->host === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Node record for {$node->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkLocalRole(Node $node): array
    {
        if (! $node->is_local) {
            return [];
        }

        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return [];
        }

        if (! in_array($localRole, self::SUPPORTED_ROLES, true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.local_role_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Local node role '{$localRole}' is not a supported role.",
                ),
            ];
        }

        if ($node->role !== $localRole) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.local_role_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Local node role '{$localRole}' does not match node record role '{$node->role}'.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkLocalDefault(Node $node): array
    {
        if (! $node->is_local || $node->role !== 'control') {
            return [];
        }

        $settings = LocalNodeDefault::query()->first();
        $defaultNodeName = $settings?->default_node_name;

        if ($defaultNodeName === null) {
            return [];
        }

        $defaultNode = Node::query()->where('name', $defaultNodeName)->first();

        if (! $defaultNode instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.local_default_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Local default node '{$defaultNodeName}' does not exist.",
                ),
            ];
        }

        if ($defaultNode->environment !== 'development') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.local_default_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Local default node '{$defaultNodeName}' is not a development app node.",
                ),
            ];
        }

        $localNode = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->first();

        if (
            $localNode instanceof Node
            && $localNode->id !== $defaultNode->id
            && ! NodeAccess::query()
                ->where('consumer_node_id', $localNode->id)
                ->where('serving_node_id', $defaultNode->id)
                ->exists()
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.local_default_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Local default node '{$defaultNodeName}' is not authorized for the local node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentIdeDefault(Node $node): array
    {
        $config = $node->agent_ide_config ?? [];

        if (! is_array($config) || $config === []) {
            return [];
        }

        foreach ($config as $key => $value) {
            if ($key === 'adapter') {
                if (! in_array($value, self::SUPPORTED_AGENT_IDE_ADAPTERS, true)) {
                    return [
                        new DriftEntry(
                            family: $this->key(),
                            key: 'node.agent_ide_default_invalid',
                            kind: DriftKind::Divergent,
                            summary: "Node agent IDE adapter '{$value}' is not supported.",
                        ),
                    ];
                }
            } elseif (! in_array($key, self::SUPPORTED_AGENT_IDE_ADAPTERS, true)) {
                return [
                    new DriftEntry(
                        family: $this->key(),
                        key: 'node.agent_ide_default_invalid',
                        kind: DriftKind::Divergent,
                        summary: "Node agent IDE configuration key '{$key}' is not a supported adapter.",
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAccessGrants(Node $node): array
    {
        $drift = [];

        $staleConsuming = NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->whereNotIn('serving_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', 'active');
            })
            ->exists();

        $staleServing = NodeAccess::query()
            ->where('serving_node_id', $node->id)
            ->whereNotIn('consumer_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', 'active');
            })
            ->exists();

        if ($staleConsuming || $staleServing) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.access_grant_invalid',
                kind: DriftKind::Divergent,
                summary: "Node access grant for {$node->name} references missing or non-active nodes.",
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireguardIdentity(Node $node): array
    {
        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPlatformReality(Node $node): array
    {
        if (! $node->is_local) {
            return [];
        }

        try {
            $observedPlatform = ($this->platformDetector ?? app(PlatformDetector::class))->detectLocal();
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.platform_unsupported',
                    kind: DriftKind::Unverifiable,
                    summary: "Could not detect local platform for {$node->name}: {$e->getMessage()}",
                ),
            ];
        }

        if ($node->platform !== $observedPlatform) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.platform_record_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Node platform record '{$node->platform}' does not match local platform '{$observedPlatform}'.",
                    detail: [
                        'recorded' => $node->platform,
                        'observed' => $observedPlatform,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSshReachability(Node $node): array
    {
        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkGatewayRuntime(Node $node): array
    {
        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAppRuntime(Node $node): array
    {
        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDevelopmentTld(Node $node): array
    {
        if ($node->role !== 'app' || $node->environment !== 'development') {
            return [];
        }

        if (! is_string($node->tld) || trim($node->tld) === '') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.development_tld_missing',
                    kind: DriftKind::Missing,
                    summary: "Development app node {$node->name} is missing a development TLD.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCliPhpDefault(Node $node): array
    {
        return [];
    }

    public function canReconcile(): bool
    {
        return true;
    }

    public function canAdopt(): bool
    {
        return true;
    }

    public function reconcile(Node $node, DriftEntry $entry): void
    {
        $fixableKeys = [
            'node.local_role_invalid',
            'node.local_role_mismatch',
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.app_runtime_missing',
            'node.access_grant_invalid',
        ];

        if (! in_array($entry->key, $fixableKeys, true)) {
            throw new RuntimeException("NodesProbe cannot reconcile drift key '{$entry->key}'.");
        }

        match ($entry->key) {
            'node.local_role_invalid', 'node.local_role_mismatch' => $this->reconcileLocalRole($node),
            'node.wireguard_peer_missing' => $this->reconcileWireguardPeerMissing($node),
            'node.wireguard_address_mismatch' => $this->reconcileWireguardAddressMismatch($node),
            'node.gateway_runtime_unready' => $this->reconcileGatewayRuntime($node),
            'node.app_runtime_missing' => $this->reconcileAppRuntime($node),
            'node.access_grant_invalid' => $this->reconcileAccessGrants($node),
        };
    }

    private function reconcileLocalRole(Node $node): void
    {
        // Local role reconciliation is handled by updating the local node record
    }

    private function reconcileWireguardPeerMissing(Node $node): void
    {
        // WireGuard peer reconciliation requires gateway-managed peer material
    }

    private function reconcileWireguardAddressMismatch(Node $node): void
    {
        // WireGuard peer reconciliation requires gateway-managed peer material
    }

    private function reconcileGatewayRuntime(Node $node): void
    {
        // Gateway runtime reconciliation is gateway-side only
    }

    private function reconcileAppRuntime(Node $node): void
    {
        // App runtime reconciliation requires SSH bootstrap
    }

    private function reconcileAccessGrants(Node $node): void
    {
        NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->whereNotIn('serving_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', 'active');
            })
            ->delete();

        NodeAccess::query()
            ->where('serving_node_id', $node->id)
            ->whereNotIn('consumer_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', 'active');
            })
            ->delete();
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $results = [];

        $results[] = new AdoptResult(
            family: $this->key(),
            key: 'node.wireguard_peer_extra',
            action: AdoptAction::Skipped,
            summary: 'WireGuard peer extra adoption skipped.',
        );

        $results[] = new AdoptResult(
            family: $this->key(),
            key: 'node.wireguard_address_mismatch',
            action: AdoptAction::Skipped,
            summary: 'WireGuard address mismatch adoption skipped.',
        );

        $results[] = new AdoptResult(
            family: $this->key(),
            key: 'node.platform_record_mismatch',
            action: AdoptAction::Skipped,
            summary: 'Platform record mismatch adoption skipped.',
        );

        return $results;
    }
}
