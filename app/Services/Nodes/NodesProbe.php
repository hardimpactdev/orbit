<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\Nodes\NodeIdentityArtifact;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\NodeRoleDefinition;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use App\Services\Platform\PlatformDetector;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\WireGuard\WireGuardPeerRealityProbe;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class NodesProbe
{
    public function __construct(
        private ?PlatformDetector $platformDetector = null,
        private ?RemoteShell $remoteShell = null,
        private ?RuntimeBackendProbe $runtimeBackendProbe = null,
        private ?WireGuardPeerRealityProbe $wireGuardPeerRealityProbe = null,
        private ?NodeIdentityArtifactProbe $nodeIdentityArtifactProbe = null,
        private ?DevelopmentDnsMappingProbe $developmentDnsMappingProbe = null,
        private ?NodeAgentIdeDefaults $agentIdeDefaults = null,
        private ?NodeRoleRegistry $nodeRoleRegistry = null,
        private ?NodeRoleBaselineConverger $nodeRoleBaselineConverger = null,
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

        $drift = array_merge($drift, $this->checkRoleAssignments($node));
        $drift = array_merge($drift, $this->checkRecordCompleteness($node));
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
    private function checkRoleAssignments(Node $node): array
    {
        $assignments = $node->relationLoaded('roleAssignments')
            ? $node->roleAssignments
            : $node->roleAssignments()->orderBy('role')->get();

        $drift = [];

        if ($this->missingCompatibleLegacyRoleAssignment($node, $assignments->all())) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.role_assignment_missing',
                kind: DriftKind::Missing,
                summary: "Node {$node->name} is missing the active role assignment implied by its legacy role fields.",
            );
        }

        $activeDefinitions = [];
        $invalidRoles = [];

        foreach ($assignments as $assignment) {
            try {
                $definition = $this->nodeRoleRegistry()->definition($assignment->role);
            } catch (InvalidArgumentException) {
                $invalidRoles[] = $assignment->role;

                continue;
            }

            if (! in_array($assignment->status, $this->validRoleAssignmentStatuses(), true)) {
                $invalidRoles[] = $assignment->role;

                continue;
            }

            if (! $this->assignmentSettingsAreValid($definition, $assignment)) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'node.role_settings_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Role assignment '{$assignment->role}' on node {$node->name} has invalid settings.",
                    detail: [
                        'role' => $assignment->role,
                    ],
                );

                continue;
            }

            if ($assignment->status === NodeRoleStatus::Error->value) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'node.role_convergence_failed',
                    kind: DriftKind::Divergent,
                    summary: "Role assignment '{$assignment->role}' on node {$node->name} failed convergence.",
                    detail: [
                        'role' => $assignment->role,
                    ],
                );

                continue;
            }

            if ($assignment->status !== NodeRoleStatus::Active->value) {
                continue;
            }

            $activeDefinitions[$assignment->role] = $definition;
        }

        if ($invalidRoles !== []) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.role_assignment_invalid',
                kind: DriftKind::Divergent,
                summary: "Node {$node->name} has invalid role assignments: ".implode(', ', $invalidRoles).'.',
            );
        }

        if ($this->activeAssignmentsConflict($assignments->all(), $activeDefinitions)) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.role_conflict',
                kind: DriftKind::Divergent,
                summary: "Node {$node->name} has conflicting active role assignments.",
            );
        }

        foreach ($assignments as $assignment) {
            if ($assignment->status !== NodeRoleStatus::Active->value) {
                continue;
            }

            if (! isset($activeDefinitions[$assignment->role])) {
                continue;
            }

            $baselineDrift = $this->baselineDriftForAssignment($node, $assignment);

            if ($baselineDrift instanceof DriftEntry) {
                $drift[] = $baselineDrift;
            }
        }

        return $drift;
    }

    /**
     * @param  list<NodeRoleAssignment>  $assignments
     */
    private function missingCompatibleLegacyRoleAssignment(Node $node, array $assignments): bool
    {
        if ($node->status !== 'active') {
            return false;
        }

        $expectedRoles = match ($node->role) {
            'gateway' => [NodeRoleName::Gateway->value],
            'app' => match ($node->environment) {
                'development' => [NodeRoleName::AppDevelopment->value],
                'production' => [NodeRoleName::AppProduction->value],
                default => [NodeRoleName::AppDevelopment->value, NodeRoleName::AppProduction->value],
            },
            default => [],
        };

        if ($expectedRoles === []) {
            return false;
        }

        foreach ($assignments as $assignment) {
            if (
                $assignment->status === NodeRoleStatus::Active->value
                && in_array($assignment->role, $expectedRoles, true)
            ) {
                return false;
            }
        }

        return true;
    }

    private function assignmentSettingsAreValid(NodeRoleDefinition $definition, NodeRoleAssignment $assignment): bool
    {
        try {
            $definition->settingsFromArray(is_array($assignment->settings) ? $assignment->settings : []);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<NodeRoleAssignment>  $assignments
     * @param  array<string, NodeRoleDefinition>  $activeDefinitions
     */
    private function activeAssignmentsConflict(array $assignments, array $activeDefinitions): bool
    {
        $activeRoles = array_values(array_map(
            fn (NodeRoleAssignment $assignment): string => $assignment->role,
            array_filter(
                $assignments,
                fn (NodeRoleAssignment $assignment): bool => $assignment->status === NodeRoleStatus::Active->value,
            ),
        ));

        foreach ($activeRoles as $role) {
            $definition = $activeDefinitions[$role] ?? null;

            if (! $definition instanceof NodeRoleDefinition) {
                continue;
            }

            foreach ($definition->conflictsWith as $conflictingRole) {
                if (in_array($conflictingRole, $activeRoles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function baselineDriftForAssignment(Node $node, NodeRoleAssignment $assignment): ?DriftEntry
    {
        if ($assignment->role !== NodeRoleName::AppDevelopment->value) {
            return null;
        }

        $settings = $assignment->settings ?? [];
        $tld = is_array($settings) ? ($settings['tld'] ?? null) : null;

        if (! is_string($tld) || trim($tld) === '') {
            return null;
        }

        $mapping = $this->developmentDnsMappingProbe()->inspect(
            $this->developmentNodeFromAssignment($node, $tld),
        );

        if (($mapping['expected_target'] ?? null) === null) {
            return new DriftEntry(
                family: $this->key(),
                key: 'node.role_baseline_mismatch',
                kind: DriftKind::Missing,
                summary: "Role baseline for '{$assignment->role}' on node {$node->name} is incomplete.",
                detail: [
                    ...$mapping,
                    'role' => $assignment->role,
                    'tld' => $tld,
                ],
            );
        }

        if (
            ($mapping['exists'] ?? false) !== true
            || ($mapping['actual_target'] ?? null) !== ($mapping['expected_target'] ?? null)
            || ($mapping['actual_owner'] ?? null) !== ($mapping['expected_owner'] ?? null)
            || ($mapping['public_exposure'] ?? false) === true
        ) {
            return new DriftEntry(
                family: $this->key(),
                key: 'node.role_baseline_mismatch',
                kind: ($mapping['exists'] ?? false) === true ? DriftKind::Divergent : DriftKind::Missing,
                summary: "Role baseline for '{$assignment->role}' on node {$node->name} is missing or mismatched.",
                detail: [
                    ...$mapping,
                    'role' => $assignment->role,
                    'tld' => $tld,
                ],
            );
        }

        return null;
    }

    private function developmentNodeFromAssignment(Node $node, string $tld): Node
    {
        $developmentNode = clone $node;
        $developmentNode->role = 'app';
        $developmentNode->environment = 'development';
        $developmentNode->status = 'active';
        $developmentNode->tld = $tld;

        return $developmentNode;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkLocalDefault(Node $node): array
    {
        if ($node->role !== 'control') {
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

        if (
            ! NodeAccess::query()
                ->where('consumer_node_id', $node->id)
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
                if (! in_array($value, $this->agentIdeDefaults()->supportedAdapters(), true)) {
                    return [
                        new DriftEntry(
                            family: $this->key(),
                            key: 'node.agent_ide_default_invalid',
                            kind: DriftKind::Divergent,
                            summary: "Node agent IDE adapter '{$value}' is not supported.",
                        ),
                    ];
                }
            } elseif (! in_array($key, $this->agentIdeDefaults()->supportedAdapters(), true)) {
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
        $peer = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->first();

        if ($node->status !== 'active') {
            if ($peer instanceof WireGuardPeer) {
                return [
                    new DriftEntry(
                        family: $this->key(),
                        key: 'node.wireguard_peer_extra',
                        kind: DriftKind::Extra,
                        summary: "WireGuard peer for non-active node {$node->name} is still present.",
                    ),
                ];
            }

            return [];
        }

        if (app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return [];
        }

        if (! is_string($node->wireguard_address) || $node->wireguard_address === '') {
            return [];
        }

        if (! $peer instanceof WireGuardPeer) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.wireguard_peer_missing',
                    kind: DriftKind::Missing,
                    summary: "WireGuard peer for node {$node->name} is missing.",
                ),
            ];
        }

        if (! $this->peerAllowsWireGuardAddress($peer, $node->wireguard_address)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.wireguard_address_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "WireGuard peer for node {$node->name} does not allow recorded address {$node->wireguard_address}.",
                    detail: [
                        'recorded' => $node->wireguard_address,
                        'allowed_ips' => $peer->allowed_ips,
                    ],
                ),
            ];
        }

        return [];
    }

    private function peerAllowsWireGuardAddress(WireGuardPeer $peer, string $wireGuardAddress): bool
    {
        return in_array($wireGuardAddress, $this->peerAllowedAddresses($peer), true);
    }

    /**
     * @return list<string>
     */
    private function peerAllowedAddresses(WireGuardPeer $peer): array
    {
        if (! is_string($peer->allowed_ips) || trim($peer->allowed_ips) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $allowedIp): string => trim(explode('/', trim($allowedIp), 2)[0]),
            explode(',', $peer->allowed_ips),
        )));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPlatformReality(Node $node): array
    {
        if (! (bool) config('orbit.is_gateway', false) || ! app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
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
        if ($node->status !== 'active' || ! app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)) {
            return [];
        }

        try {
            $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, 'true', [
                'timeout' => 10,
            ]);
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.app_ssh_unreachable',
                    kind: DriftKind::Unverifiable,
                    summary: "Gateway cannot reach app node {$node->name} over SSH: {$e->getMessage()}",
                    detail: [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                ),
            ];
        }

        if (! $result->successful()) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.app_ssh_unreachable',
                    kind: DriftKind::Unverifiable,
                    summary: "Gateway cannot reach app node {$node->name} over SSH.",
                    detail: [
                        'exit_code' => $result->exitCode,
                        'output' => trim($result->output()),
                    ],
                ),
            ];
        }

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
        if ($node->status !== 'active' || ! app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)) {
            return [];
        }

        try {
            $result = $this->runtimeBackendProbe()->check($node);
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.app_runtime_missing',
                    kind: DriftKind::Unverifiable,
                    summary: "App node {$node->name} runtime readiness could not be verified: {$e->getMessage()}",
                    detail: [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                ),
            ];
        }

        if (! $result->available) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.app_runtime_missing',
                    kind: DriftKind::Unverifiable,
                    summary: "App node {$node->name} is missing the required runtime backend.",
                    detail: [
                        'exit_code' => $result->exitCode,
                        'output' => $result->output,
                    ],
                ),
            ];
        }

        return [];
    }

    private function runtimeBackendProbe(): RuntimeBackendProbe
    {
        return $this->runtimeBackendProbe
            ?? ($this->remoteShell instanceof RemoteShell
                ? new RuntimeBackendProbe($this->remoteShell)
                : app(RuntimeBackendProbe::class));
    }

    private function wireGuardPeerRealityProbe(): WireGuardPeerRealityProbe
    {
        return $this->wireGuardPeerRealityProbe ?? app(WireGuardPeerRealityProbe::class);
    }

    private function nodeIdentityArtifactProbe(): NodeIdentityArtifactProbe
    {
        return $this->nodeIdentityArtifactProbe ?? ($this->remoteShell instanceof RemoteShell
            ? new NodeIdentityArtifactProbe($this->remoteShell)
            : app(NodeIdentityArtifactProbe::class));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDevelopmentTld(Node $node): array
    {
        return [];
    }

    private function developmentDnsMappingProbe(): DevelopmentDnsMappingProbe
    {
        return $this->developmentDnsMappingProbe ?? app(DevelopmentDnsMappingProbe::class);
    }

    private function agentIdeDefaults(): NodeAgentIdeDefaults
    {
        return $this->agentIdeDefaults ?? app(NodeAgentIdeDefaults::class);
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
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.app_runtime_missing',
            'node.access_grant_invalid',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
        ];

        if (! in_array($entry->key, $fixableKeys, true)) {
            throw new RuntimeException("NodesProbe cannot reconcile drift key '{$entry->key}'.");
        }

        match ($entry->key) {
            'node.wireguard_peer_missing' => $this->reconcileWireguardPeerMissing($node),
            'node.wireguard_address_mismatch' => $this->reconcileWireguardAddressMismatch($node),
            'node.gateway_runtime_unready' => $this->reconcileGatewayRuntime($node),
            'node.app_runtime_missing' => $this->reconcileAppRuntime($node),
            'node.access_grant_invalid' => $this->reconcileAccessGrants($node),
            'node.role_convergence_failed' => $this->reconcileRoleConvergenceFailures($node, $entry),
            'node.role_baseline_mismatch' => $this->reconcileRoleBaselineMismatch($node, $entry),
        };
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

    private function reconcileRoleConvergenceFailures(Node $node, DriftEntry $entry): void
    {
        $assignment = $this->roleAssignmentForRepair($node, $entry, NodeRoleStatus::Error->value);

        if (! $assignment instanceof NodeRoleAssignment) {
            return;
        }

        try {
            $this->nodeRoleBaselineConverger()->converge($node, $assignment);

            $assignment->forceFill([
                'status' => NodeRoleStatus::Active->value,
                'last_error' => null,
                'converged_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
                'converged_at' => null,
            ])->save();
        }
    }

    private function reconcileRoleBaselineMismatch(Node $node, DriftEntry $entry): void
    {
        $assignment = $this->roleAssignmentForRepair($node, $entry, NodeRoleStatus::Active->value);

        if (! $assignment instanceof NodeRoleAssignment) {
            return;
        }

        $this->nodeRoleBaselineConverger()->converge($node, $assignment);
    }

    private function nodeRoleRegistry(): NodeRoleRegistry
    {
        return $this->nodeRoleRegistry ?? app(NodeRoleRegistry::class);
    }

    private function nodeRoleBaselineConverger(): NodeRoleBaselineConverger
    {
        return $this->nodeRoleBaselineConverger ?? app(NodeRoleBaselineConverger::class);
    }

    private function roleAssignmentForRepair(Node $node, DriftEntry $entry, string $status): ?NodeRoleAssignment
    {
        $role = is_string($entry->detail['role'] ?? null) ? $entry->detail['role'] : null;

        if ($role === null) {
            return null;
        }

        return $node->roleAssignments()
            ->where('role', $role)
            ->where('status', $status)
            ->first();
    }

    /**
     * @return list<string>
     */
    private function validRoleAssignmentStatuses(): array
    {
        return array_map(
            static fn (NodeRoleStatus $status): string => $status->value,
            NodeRoleStatus::cases(),
        );
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        $items = [];

        $peer = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->first();

        if (
            $node->status === 'active'
            && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)
            && is_string($node->wireguard_address)
            && $node->wireguard_address !== ''
            && ! $peer instanceof WireGuardPeer
        ) {
            try {
                $artifact = $this->nodeIdentityArtifactProbe()->read($node);
                $publicKey = $artifact->interfacePublicKey;
                $peerReality = is_string($publicKey) && $publicKey !== ''
                    ? $this->wireGuardPeerRealityProbe()->peers()[$publicKey] ?? null
                    : null;
            } catch (Throwable) {
                $artifact = null;
                $publicKey = null;
                $peerReality = null;
            }

            if (
                $artifact instanceof NodeIdentityArtifact
                && is_string($publicKey)
                && $publicKey !== ''
                && $peerReality !== null
                && count($peerReality->allowedAddresses) === 1
                && $this->identityArtifactMatchesNode($node, $artifact, $peerReality->allowedAddresses[0])
            ) {
                $items['node.wireguard_peer_missing'] = [
                    'public_key' => $publicKey,
                    'observed' => $peerReality->allowedAddresses[0],
                    'allowed_ips' => $peerReality->allowedIps,
                    'artifact' => [
                        'name' => $artifact->name,
                        'role' => $artifact->role,
                        'local_role' => $artifact->localRole,
                        'status' => $artifact->status,
                        'platform' => $artifact->platform,
                        'wireguard_address' => $artifact->wireguardAddress,
                        'registry_public_key' => $artifact->registryPublicKey,
                        'interface_public_key' => $artifact->interfacePublicKey,
                    ],
                ];
            }
        }

        if (
            $node->status !== 'active'
            && ! app(NodeRoleAssignments::class)->nodeIsGateway($node)
            && $peer instanceof WireGuardPeer
            && is_string($peer->public_key)
            && $peer->public_key !== ''
        ) {
            try {
                $peerReality = $this->wireGuardPeerRealityProbe()->peers()[$peer->public_key] ?? null;
            } catch (Throwable) {
                $peerReality = null;
            }

            if ($peerReality !== null && count($peerReality->allowedAddresses) === 1) {
                $items['node.wireguard_peer_extra'] = [
                    'recorded_status' => $node->status,
                    'public_key' => $peer->public_key,
                    'observed' => $peerReality->allowedAddresses[0],
                    'allowed_ips' => $peerReality->allowedIps,
                ];
            }
        }

        if (
            $node->status === 'active'
            && ! app(NodeRoleAssignments::class)->nodeIsGateway($node)
            && is_string($node->wireguard_address)
            && $node->wireguard_address !== ''
            && $peer instanceof WireGuardPeer
            && ! $this->peerAllowsWireGuardAddress($peer, $node->wireguard_address)
        ) {
            $allowedAddresses = $this->peerAllowedAddresses($peer);

            if (count($allowedAddresses) === 1) {
                $items['node.wireguard_address_mismatch'] = [
                    'recorded' => $node->wireguard_address,
                    'observed' => $allowedAddresses[0],
                    'allowed_ips' => $peer->allowed_ips,
                ];
            }
        }

        if ($node->status === 'active' && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)) {
            try {
                $runtimeBackend = $this->runtimeBackendProbe()->check($node);

                $items['node.app_runtime_missing'] = [
                    'available' => $runtimeBackend->available,
                    'exit_code' => $runtimeBackend->exitCode,
                    'output' => $runtimeBackend->output,
                ];
            } catch (Throwable $e) {
                $items['node.app_runtime_missing'] = [
                    'available' => false,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ((bool) config('orbit.is_gateway', false) && app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            try {
                $observedPlatform = ($this->platformDetector ?? app(PlatformDetector::class))->detectLocal();
            } catch (Throwable) {
                $observedPlatform = null;
            }

            if (is_string($observedPlatform) && $observedPlatform !== '' && $node->platform !== $observedPlatform) {
                $items['node.platform_record_mismatch'] = [
                    'recorded' => $node->platform,
                    'observed' => $observedPlatform,
                ];
            }
        }

        return new ProbeSnapshot($items);
    }

    private function identityArtifactMatchesNode(Node $node, NodeIdentityArtifact $artifact, string $observedAddress): bool
    {
        return $artifact->name === $node->name
            && $artifact->role === $node->role
            && $artifact->localRole === $node->role
            && $artifact->status === 'active'
            && $artifact->platform === $node->platform
            && $artifact->wireguardAddress === $node->wireguard_address
            && $observedAddress === $node->wireguard_address;
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $results = [];

        $wireGuardPeerMissing = $snapshot->get('node.wireguard_peer_missing');

        if (! is_array($wireGuardPeerMissing)) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.wireguard_peer_missing',
                action: AdoptAction::Skipped,
                summary: 'WireGuard peer missing adoption skipped.',
            );
        } else {
            $publicKey = $wireGuardPeerMissing['public_key'] ?? null;
            $observedAddress = $wireGuardPeerMissing['observed'] ?? null;

            if (is_string($publicKey) && $publicKey !== '' && is_string($observedAddress) && $observedAddress !== '') {
                $allowedIps = array_values(array_filter(
                    is_array($wireGuardPeerMissing['allowed_ips'] ?? null)
                        ? $wireGuardPeerMissing['allowed_ips']
                        : [],
                    is_string(...),
                ));

                WireGuardPeer::query()->updateOrCreate(
                    ['node_id' => $node->id],
                    [
                        'public_key' => $publicKey,
                        'private_key' => '',
                        'allowed_ips' => $allowedIps !== [] ? implode(',', $allowedIps) : "{$observedAddress}/32",
                    ],
                );

                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_peer_missing',
                    action: AdoptAction::Updated,
                    summary: "Attached compatible live WireGuard peer reality to {$node->name}.",
                    detail: $wireGuardPeerMissing,
                );
            } else {
                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_peer_missing',
                    action: AdoptAction::Skipped,
                    summary: 'WireGuard peer missing adoption skipped because public key or address proof is unavailable.',
                    detail: $wireGuardPeerMissing,
                );
            }
        }

        $wireGuardPeerExtra = $snapshot->get('node.wireguard_peer_extra');

        if ($wireGuardPeerExtra === null) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.wireguard_peer_extra',
                action: AdoptAction::Skipped,
                summary: 'WireGuard peer extra adoption skipped.',
            );
        } else {
            $observedAddress = $wireGuardPeerExtra['observed'] ?? null;

            if (is_string($observedAddress) && $observedAddress !== '') {
                $node->update([
                    'status' => 'active',
                    'wireguard_address' => $observedAddress,
                ]);

                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_peer_extra',
                    action: AdoptAction::Updated,
                    summary: "Activated node {$node->name} from compatible live WireGuard peer reality.",
                    detail: [
                        'recorded_status' => $wireGuardPeerExtra['recorded_status'] ?? null,
                        'public_key' => $wireGuardPeerExtra['public_key'] ?? null,
                        'observed' => $observedAddress,
                        'allowed_ips' => $wireGuardPeerExtra['allowed_ips'] ?? [],
                    ],
                );
            } else {
                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_peer_extra',
                    action: AdoptAction::Skipped,
                    summary: 'WireGuard peer extra adoption skipped because the observed address is unavailable.',
                    detail: $wireGuardPeerExtra,
                );
            }
        }

        $wireGuardAddressMismatch = $snapshot->get('node.wireguard_address_mismatch');

        if ($wireGuardAddressMismatch === null) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.wireguard_address_mismatch',
                action: AdoptAction::Skipped,
                summary: 'WireGuard address mismatch adoption skipped.',
            );
        } else {
            $observedAddress = $wireGuardAddressMismatch['observed'] ?? null;

            if (is_string($observedAddress) && $observedAddress !== '') {
                $node->update(['wireguard_address' => $observedAddress]);

                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_address_mismatch',
                    action: AdoptAction::Updated,
                    summary: "Updated WireGuard address for {$node->name} to {$observedAddress}.",
                    detail: [
                        'recorded' => $wireGuardAddressMismatch['recorded'] ?? $node->wireguard_address,
                        'observed' => $observedAddress,
                        'allowed_ips' => $wireGuardAddressMismatch['allowed_ips'] ?? null,
                    ],
                );
            } else {
                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: 'node.wireguard_address_mismatch',
                    action: AdoptAction::Skipped,
                    summary: 'WireGuard address mismatch adoption skipped because the observed address is unavailable.',
                    detail: $wireGuardAddressMismatch,
                );
            }
        }

        $appRuntimeMissing = $snapshot->get('node.app_runtime_missing');

        if ($appRuntimeMissing === null) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.app_runtime_missing',
                action: AdoptAction::Skipped,
                summary: 'App runtime readiness adoption skipped.',
            );
        } elseif (($appRuntimeMissing['available'] ?? null) === true) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.app_runtime_missing',
                action: AdoptAction::Updated,
                summary: "Verified app runtime readiness for {$node->name}.",
                detail: $appRuntimeMissing,
            );
        } else {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.app_runtime_missing',
                action: AdoptAction::Conflict,
                summary: "App runtime readiness for {$node->name} cannot be adopted because the runtime is unavailable.",
                detail: $appRuntimeMissing,
            );
        }

        $platformRecordMismatch = $snapshot->get('node.platform_record_mismatch');

        if ($platformRecordMismatch === null) {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.platform_record_mismatch',
                action: AdoptAction::Skipped,
                summary: 'Platform record mismatch adoption skipped.',
            );

            return $results;
        }

        $observedPlatform = $platformRecordMismatch['observed'] ?? null;

        if (! is_string($observedPlatform) || $observedPlatform === '') {
            $results[] = new AdoptResult(
                family: $this->key(),
                key: 'node.platform_record_mismatch',
                action: AdoptAction::Skipped,
                summary: 'Platform record mismatch adoption skipped because the observed platform is unavailable.',
                detail: $platformRecordMismatch,
            );

            return $results;
        }

        $node->update(['platform' => $observedPlatform]);

        $results[] = new AdoptResult(
            family: $this->key(),
            key: 'node.platform_record_mismatch',
            action: AdoptAction::Updated,
            summary: "Updated platform record for {$node->name} to {$observedPlatform}.",
            detail: [
                'recorded' => $platformRecordMismatch['recorded'] ?? $node->platform,
                'observed' => $observedPlatform,
            ],
        );

        return $results;
    }
}
