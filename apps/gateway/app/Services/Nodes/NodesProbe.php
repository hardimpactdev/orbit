<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\Nodes\NodeIdentityArtifact;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\WireGuardPeer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Doctor\DoctorRestoreActionId;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Nodes\Roles\NodeRoleActivator;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\NodeRoleDefinition;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use App\Services\Nodes\Roles\NodeToolBaselineConfigRenderer;
use App\Services\Platform\PlatformDetector;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Updates\UpdateDriverRegistry;
use App\Services\Updates\UpdatePostureIssue;
use App\Services\Updates\UpdateTargetFactory;
use App\Services\WireGuard\WireGuardPeerRealityProbe;
use InvalidArgumentException;
use JsonException;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;
use Throwable;

final readonly class NodesProbe
{
    public function __construct(
        private ?PlatformDetector $platformDetector = null,
        private ?RuntimeBackendProbe $runtimeBackendProbe = null,
        private ?WireGuardPeerRealityProbe $wireGuardPeerRealityProbe = null,
        private ?NodeIdentityArtifactProbe $nodeIdentityArtifactProbe = null,
        private ?NodeSecurityPostureProbe $nodeSecurityPostureProbe = null,
        private ?NodeRoleRegistry $nodeRoleRegistry = null,
        private ?NodeRoleActivator $nodeRoleActivator = null,
        private ?NodeRoleBaselineConverger $nodeRoleBaselineConverger = null,
        private ?UpdateDriverRegistry $updateDriverRegistry = null,
        private ?UpdateTargetFactory $updateTargetFactory = null,
        private ?RunsInternalCommands $localExecutor = null,
        private ?DnsmasqReconciler $dnsmasqReconciler = null,
    ) {}

    public function key(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Node';
    }

    public function introspect(Node $node): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Node $node, ProbeSnapshot $snapshot, ?string $key = null): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRoleAssignments($node));
        $drift = array_merge($drift, $this->checkAgentIntent($node));
        $drift = array_merge($drift, $this->checkRecordCompleteness($node));
        $drift = array_merge($drift, $this->checkAccessGrants($node));
        $drift = array_merge($drift, $this->checkWireguardIdentity($node));
        $drift = array_merge($drift, $this->checkPlatformReality($node));
        $drift = array_merge($drift, $this->checkNodeReachability($node));
        $drift = array_merge($drift, $this->checkGatewayRuntime($node));
        $drift = array_merge($drift, $this->checkAppRuntime($node));
        $drift = array_merge($drift, $this->checkCliPhpDefault($node));
        $drift = array_merge($drift, $this->nodeSecurityPostureProbe()->diff($node));

        if ($key === 'node.updates') {
            $drift = array_merge($drift, $this->checkUpdates($node));
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    public function roleBaselineDrift(Node $node): array
    {
        $drift = [];

        foreach ($node->roleAssignments()->where('status', NodeRoleStatus::Active->value)->get() as $assignment) {
            $drift = [
                ...$drift,
                ...$this->baselineDriftForAssignment($node, $assignment),
            ];
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Node $node): array
    {
        if ($this->nodeIsMissingRequiredRecordFields($node)) {
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
    private function checkAgentIntent(Node $node): array
    {
        $drift = [];

        if ($node->managed && ! $node->isOperator()) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.managed_agent_intent_invalid',
                kind: DriftKind::Divergent,
                summary: "Managed Agent intent on role-bearing node {$node->name} is invalid.",
                detail: [
                    'reason' => $node->hasActiveRole(NodeRoleName::Gateway->value)
                        ? 'gateway_excluded'
                        : 'role_bearing_node',
                    'managed' => true,
                ],
            );
        }

        if (! $node->hasAgentIntent() && $node->installed_agent !== null) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.agent_expectation_stale',
                kind: DriftKind::Extra,
                summary: "Node {$node->name} retains installed Agent expectation without Agent intent.",
                detail: [
                    'reason' => 'no_agent_intent',
                ],
            );
        }

        return $drift;
    }

    private function nodeIsMissingRequiredRecordFields(Node $node): bool
    {
        $rawStatus = $node->getRawOriginal('status');

        return (
            ! is_string($rawStatus)
            || $rawStatus === ''
            || ! is_string($node->platform)
            || $node->platform === ''
            || ! is_string($node->wireguard_address)
            || $node->wireguard_address === ''
            || $node->isActive() && ! NodeTld::isValid($node->tld)
            || $this->nodeIsMissingRequiredHost($node)
        );
    }

    private function nodeIsMissingRequiredHost(Node $node): bool
    {
        if (! $this->nodeRequiresHost($node)) {
            return false;
        }

        return ! is_string($node->host) || $node->host === '';
    }

    private function nodeRequiresHost(Node $node): bool
    {
        return (
            $node->hasActiveRole(NodeRoleName::Gateway->value)
            || $node->hasActiveRole(NodeRoleName::AppDevelopment->value)
            || $node->hasActiveRole(NodeRoleName::AppProduction->value)
        );
    }

    private function nodeRequiresGatewaySshReachability(Node $node): bool
    {
        $roles = app(NodeRoleAssignments::class);

        return $roles->nodeHasActiveAppHostRole($node) || $roles->nodeHasActiveAgentRole($node);
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

        $activeDefinitions = [];
        $unresolvedDefinitions = [];
        $invalidRoles = [];

        foreach ($assignments as $assignment) {
            try {
                $definition = $this->nodeRoleRegistry()->definition($assignment->role);
            } catch (InvalidArgumentException) {
                $invalidRoles[] = $assignment->role;

                continue;
            }

            if (! in_array($assignment->status, NodeRoleStatus::cases(), true)) {
                $invalidRoles[] = $assignment->role;

                continue;
            }

            if ($this->assignmentStatusIsUnresolved($assignment)) {
                $unresolvedDefinitions[$assignment->role] = $definition;
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

            if ($assignment->status === NodeRoleStatus::Error) {
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

            if ($assignment->status !== NodeRoleStatus::Active) {
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

        if ($this->unresolvedAssignmentsConflict($assignments->all(), $unresolvedDefinitions)) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.role_conflict',
                kind: DriftKind::Divergent,
                summary: "Node {$node->name} has conflicting unresolved role assignments.",
            );
        }

        foreach ($assignments as $assignment) {
            if ($assignment->status !== NodeRoleStatus::Active) {
                continue;
            }

            if (! isset($activeDefinitions[$assignment->role])) {
                continue;
            }

            $baselineDrift = $this->baselineDriftForAssignment($node, $assignment);
            $drift = array_merge($drift, $baselineDrift);
        }

        return $drift;
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
     * @param  array<string, NodeRoleDefinition>  $unresolvedDefinitions
     */
    private function unresolvedAssignmentsConflict(array $assignments, array $unresolvedDefinitions): bool
    {
        $unresolvedRoles = array_values(array_unique(array_map(
            fn (NodeRoleAssignment $assignment): string => $assignment->role,
            array_filter(
                $assignments,
                $this->assignmentStatusIsUnresolved(...),
            ),
        )));

        foreach ($unresolvedRoles as $role) {
            $definition = $unresolvedDefinitions[$role] ?? null;

            if (! $definition instanceof NodeRoleDefinition) {
                continue;
            }

            foreach ($definition->conflictsWith as $conflictingRole) {
                if (in_array($conflictingRole, $unresolvedRoles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assignmentStatusIsUnresolved(NodeRoleAssignment $assignment): bool
    {
        return in_array(
            $assignment->status,
            [
                NodeRoleStatus::Active,
                NodeRoleStatus::Pending,
                NodeRoleStatus::Error,
            ],
            true,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function baselineDriftForAssignment(Node $node, NodeRoleAssignment $assignment): array
    {
        return match ($assignment->role) {
            NodeRoleName::AppDevelopment->value => $this->baselineDriftForAppDevelopment($node, $assignment),
            NodeRoleName::Agent->value => $this->baselineDriftForAgent($node, $assignment),
            default => [],
        };
    }

    /**
     * @return list<DriftEntry>
     */
    private function baselineDriftForAppDevelopment(Node $node, NodeRoleAssignment $assignment): array
    {
        return $this->baselineToolConfigDrift($node, $assignment, 'caddy');
    }

    /**
     * @return list<DriftEntry>
     */
    private function baselineToolConfigDrift(Node $node, NodeRoleAssignment $assignment, string $tool): array
    {
        $expectedConfig = app(NodeToolBaselineConfigRenderer::class)->render($tool, $node);

        if ($expectedConfig === null) {
            return [];
        }

        $record = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', $tool)
            ->first();

        if (! $record instanceof NodeTool) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.role_baseline_mismatch',
                    kind: DriftKind::Missing,
                    summary: "Role baseline for '{$assignment->role}' on node {$node->name} is missing required tool {$tool}.",
                    detail: [
                        'role' => $assignment->role,
                        'component' => 'tool_config',
                        'tool' => $tool,
                    ],
                ),
            ];
        }

        $actualConfig = is_array($record->config) ? $record->config : [];

        if ($this->canonicalBaselineConfig($actualConfig) === $this->canonicalBaselineConfig($expectedConfig)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'node.role_baseline_mismatch',
                kind: DriftKind::Divergent,
                summary: "Role baseline for '{$assignment->role}' on node {$node->name} has stale tool {$tool} config.",
                detail: [
                    'role' => $assignment->role,
                    'component' => 'tool_config',
                    'tool' => $tool,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function canonicalBaselineConfig(array $config): string
    {
        $this->sortRecursive($config);

        return json_encode($config, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }

        unset($item);

        if (array_is_list($value)) {
            return;
        }

        ksort($value);
    }

    /**
     * @return list<DriftEntry>
     */
    private function baselineDriftForAgent(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        foreach (['caddy'] as $tool) {
            if (NodeTool::query()->where('node_id', $node->id)->where('name', $tool)->exists()) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'node.role_baseline_mismatch',
                kind: DriftKind::Missing,
                summary: "Role baseline for '{$assignment->role}' on node {$node->name} is missing required tool {$tool}.",
                detail: [
                    'role' => $assignment->role,
                    'tool' => $tool,
                ],
            );
        }

        if ($node->isAgentEligible()) {
            try {
                $result = $this->localExecutor()->runInternal(
                    node: $node,
                    commandName: 'internal:agent-runtime:probe',
                    transportOptions: [
                        'metadata' => [
                            'ORBIT_OPERATION_ID' => 'node-agent-runtime.probe',
                        ],
                        'timeout' => 10,
                        'throw' => false,
                    ],
                );
                $agentRuntime = $this->successData($result->stdout);

                if (! $result->successful() || ($agentRuntime['runtime_user'] ?? null) !== true) {
                    $drift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'node.role_baseline_mismatch',
                        kind: DriftKind::Missing,
                        summary: "Role baseline for '{$assignment->role}' on node {$node->name} is missing the agent runtime user.",
                        detail: [
                            'role' => $assignment->role,
                            'component' => 'agent_user',
                        ],
                    );
                }

                if (($agentRuntime['runtime_user'] ?? null) === true && ($agentRuntime['orbit_cli'] ?? null) !== true) {
                    $drift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'node.role_baseline_mismatch',
                        kind: DriftKind::Divergent,
                        summary: "Role baseline for '{$assignment->role}' on node {$node->name} does not let the agent runtime user execute the Orbit CLI.",
                        detail: [
                            'role' => $assignment->role,
                            'component' => 'agent_orbit_cli',
                        ],
                    );
                }
            } catch (Throwable) {
                // Agent runtime reachability is covered by node transport checks.
            }
        }

        return $drift;
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
                $query->select('id')->from('nodes')->where('status', NodeStatus::Active->value);
            })
            ->exists();

        $staleServing = NodeAccess::query()
            ->where('serving_node_id', $node->id)
            ->whereNotIn('consumer_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', NodeStatus::Active->value);
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

        $drift = array_merge($drift, $this->checkAccessPermissionValidity($node));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAccessPermissionValidity(Node $node): array
    {
        $drift = [];
        $registry = $this->nodePermissionRegistry();
        $normalizer = $this->nodePermissionNormalizer();

        $grants = NodeAccess::query()
            ->where(function ($query) use ($node): void {
                $query->where('consumer_node_id', $node->id)
                    ->orWhere('serving_node_id', $node->id);
            })
            ->get();

        foreach ($grants as $grant) {
            /** @var list<string> $permissions */
            $permissions = is_array($grant->permissions) ? $grant->permissions : [];

            if ($permissions === []) {
                continue;
            }

            $unknown = array_values(array_filter(
                $permissions,
                fn (string $permission): bool => ! $registry->isKnown($permission),
            ));

            if ($unknown !== []) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'node.access_permission_invalid',
                    kind: DriftKind::Divergent,
                    summary: 'Node access grant stores unknown permission strings: '.implode(', ', $unknown).'.',
                    detail: [
                        'consumer_node_id' => $grant->consumer_node_id,
                        'serving_node_id' => $grant->serving_node_id,
                        'unknown_permissions' => $unknown,
                    ],
                );

                continue;
            }

            try {
                $normalized = $normalizer->normalize($permissions);

                if ($normalized->permissions !== $permissions) {
                    $drift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'node.access_permission_invalid',
                        kind: DriftKind::Divergent,
                        summary: 'Node access grant stores redundant permissions that do not normalize cleanly.',
                        detail: [
                            'consumer_node_id' => $grant->consumer_node_id,
                            'serving_node_id' => $grant->serving_node_id,
                            'stored_permissions' => $permissions,
                            'normalized_permissions' => $normalized->permissions,
                            'removed_permissions' => $normalized->removed,
                        ],
                    );
                }
            } catch (Throwable) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'node.access_permission_invalid',
                    kind: DriftKind::Divergent,
                    summary: 'Node access grant stores permission strings that fail normalization.',
                    detail: [
                        'consumer_node_id' => $grant->consumer_node_id,
                        'serving_node_id' => $grant->serving_node_id,
                        'stored_permissions' => $permissions,
                    ],
                );
            }
        }

        return $drift;
    }

    private function nodePermissionRegistry(): NodePermissionRegistry
    {
        return app(NodePermissionRegistry::class);
    }

    private function nodePermissionNormalizer(): NodePermissionNormalizer
    {
        return app(NodePermissionNormalizer::class);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireguardIdentity(Node $node): array
    {
        $peer = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->first();

        if (! $node->isActive()) {
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
        if (! app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
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
    private function checkNodeReachability(Node $node): array
    {
        if (
            ! $node->isActive()
            || ! $this->nodeRequiresGatewaySshReachability($node)
            || $this->nodeIsMissingRequiredRecordFields($node)
        ) {
            return [];
        }

        try {
            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:executor:verify',
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'node.reachable',
                    ],
                    'timeout' => 10,
                    'throw' => false,
                ],
            );
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.transport_unreachable',
                    kind: DriftKind::Unverifiable,
                    summary: "Gateway cannot reach node {$node->name} over node transport: {$e->getMessage()}",
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
                    key: 'node.transport_unreachable',
                    kind: DriftKind::Unverifiable,
                    summary: "Gateway cannot reach node {$node->name} over node transport.",
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
        if (
            ! $node->isActive()
            || ! app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)
            || $this->nodeIsMissingRequiredRecordFields($node)
        ) {
            return [];
        }

        try {
            $result = $this->runtimeBackendProbe()->check($node);
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'node.runtime_missing',
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
                    key: 'node.runtime_missing',
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
        return $this->runtimeBackendProbe ?? new RuntimeBackendProbe($this->localExecutor());
    }

    private function wireGuardPeerRealityProbe(): WireGuardPeerRealityProbe
    {
        return $this->wireGuardPeerRealityProbe ?? app(WireGuardPeerRealityProbe::class);
    }

    private function nodeIdentityArtifactProbe(): NodeIdentityArtifactProbe
    {
        return (
            $this->nodeIdentityArtifactProbe
            ?? (
                $this->localExecutor instanceof RunsInternalCommands
                    ? new NodeIdentityArtifactProbe($this->localExecutor)
                    : app(NodeIdentityArtifactProbe::class)
            )
        );
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

    /**
     * Codes this probe's reconcile path can restore. DoctorRestoreSupport and
     * catalog restorable flags aggregate this map — do not duplicate elsewhere.
     *
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        $map = DoctorRestoreActionId::map([
            'node.managed_agent_intent_invalid',
            'node.agent_expectation_stale',
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.access_grant_invalid',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
            'node.websocket.backend_cert_missing',
            'node.websocket.bind_public_interface',
            'node.security.sshd_config',
            'node.security.sshd_listen',
            'node.security.public_ssh_deny',
            'node.security.sysctl',
            'node.security.home_perms',
        ]);

        // Emitted as key node.updates with detail.code variants; one restorer.
        foreach ([
            'node.updates',
            'node.updates_config_missing',
            'node.updates_config_mismatch',
            'node.updates_dry_run_failed',
            'node.updates_last_run_failed',
            'node.updates_unverifiable',
        ] as $code) {
            $map[$code] = 'restore_node_updates';
        }

        return $map;
    }

    public function reconcile(Node $node, DriftEntry $entry): void
    {
        if ($entry->key === 'node.updates') {
            if ($this->updateIssueCode($entry) === 'node.updates_reboot_required') {
                throw new RuntimeException('Node update reboot-required drift is not restorable.');
            }

            $this->reconcileUpdates($node);

            return;
        }

        $fixableKeys = array_values(array_filter(
            array_keys(self::restoreSupport()),
            static fn (string $code): bool => ! str_starts_with($code, 'node.updates'),
        ));

        if (! in_array($entry->key, $fixableKeys, true)) {
            throw new RuntimeException("NodesProbe cannot reconcile drift key '{$entry->key}'.");
        }

        if (str_starts_with($entry->key, 'node.security.')) {
            $this->nodeSecurityPostureProbe()->restore($node, $entry);

            return;
        }

        match ($entry->key) {
            'node.managed_agent_intent_invalid' => $node->forceFill(['managed' => false])->save(),
            'node.agent_expectation_stale' => $node->forceFill(['installed_agent' => null])->save(),
            'node.wireguard_peer_missing' => $this->reconcileWireguardPeerMissing($node),
            'node.wireguard_address_mismatch' => $this->reconcileWireguardAddressMismatch($node),
            'node.gateway_runtime_unready' => $this->reconcileGatewayService($node),
            'node.access_grant_invalid' => $this->reconcileAccessGrants($node),
            'node.role_convergence_failed' => $this->reconcileRoleConvergenceFailures($node, $entry),
            'node.role_baseline_mismatch',
            'node.websocket.backend_cert_missing',
            'node.websocket.bind_public_interface',
                => $this->reconcileRoleBaselineMismatch($node, $entry),
            default => throw new RuntimeException("NodesProbe cannot reconcile drift key '{$entry->key}'."),
        };
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkUpdates(Node $node): array
    {
        $target = $this->updateTargetFactory()->forNode($node);
        $drivers = $this->updateDriverRegistry()->driversFor($target);

        if ($drivers === []) {
            return [];
        }

        $drift = [];

        foreach ($drivers as $driver) {
            $snapshot = $driver->probe($target);

            foreach ($snapshot->issues as $issue) {
                $drift[] = $this->updateIssueDriftEntry($snapshot->driver, $issue);
            }
        }

        return $drift;
    }

    private function updateIssueDriftEntry(string $driver, UpdatePostureIssue $issue): DriftEntry
    {
        return new DriftEntry(
            family: $this->key(),
            key: 'node.updates',
            kind: $issue->kind,
            summary: $issue->summary,
            detail: [
                'driver' => $driver,
                ...$issue->detail,
                'code' => $issue->code,
            ],
        );
    }

    private function reconcileUpdates(Node $node): void
    {
        $target = $this->updateTargetFactory()->forNode($node);
        $drivers = $this->updateDriverRegistry()->driversFor($target);

        if ($drivers === []) {
            throw new RuntimeException("No update driver supports node '{$node->name}'.");
        }

        foreach ($drivers as $driver) {
            $result = $driver->apply($target);

            if ($result->status !== 'completed') {
                throw new RuntimeException($result->summary);
            }
        }
    }

    private function updateIssueCode(DriftEntry $entry): string
    {
        return is_string($entry->detail['code'] ?? null) ? $entry->detail['code'] : $entry->key;
    }

    private function reconcileWireguardPeerMissing(Node $node): void
    {
        // WireGuard peer reconciliation requires gateway-managed peer material
    }

    private function reconcileWireguardAddressMismatch(Node $node): void
    {
        // WireGuard peer reconciliation requires gateway-managed peer material
    }

    private function reconcileGatewayService(Node $node): void
    {
        // Gateway service reconciliation is gateway-side only
    }

    private function reconcileAccessGrants(Node $node): void
    {
        NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->whereNotIn('serving_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', NodeStatus::Active->value);
            })
            ->delete();

        NodeAccess::query()
            ->where('serving_node_id', $node->id)
            ->whereNotIn('consumer_node_id', function ($query): void {
                $query->select('id')->from('nodes')->where('status', NodeStatus::Active->value);
            })
            ->delete();
    }

    private function reconcileRoleConvergenceFailures(Node $node, DriftEntry $entry): void
    {
        $assignment = $this->roleAssignmentForRepair($node, $entry, NodeRoleStatus::Error->value);

        if (! $assignment instanceof NodeRoleAssignment) {
            return;
        }

        $roleActivator = $this->nodeRoleActivator();
        $roleActivator->ensureCanActivate($node, $assignment->role);

        try {
            $this->nodeRoleBaselineConverger()->converge($node, $assignment);
            $roleActivator->activate($node, $assignment);
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
                'converged_at' => null,
            ])->save();

            throw $throwable;
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

    private function nodeRoleActivator(): NodeRoleActivator
    {
        return $this->nodeRoleActivator ?? app(NodeRoleActivator::class);
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

        return $node
            ->roleAssignments()
            ->where('role', $role)
            ->where('status', $status)
            ->first();
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        $items = [];

        $peer = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->first();

        if (
            $node->isActive()
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
            ! $node->isActive()
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
                    'recorded_status' => $node->status->value,
                    'public_key' => $peer->public_key,
                    'observed' => $peerReality->allowedAddresses[0],
                    'allowed_ips' => $peerReality->allowedIps,
                ];
            }
        }

        if (
            $node->isActive()
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

        if (app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
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

        foreach ($this->nodeSecurityPostureProbe()->snapshotForAdopt($node)->items as $key => $item) {
            $items[$key] = $item;
        }

        return new ProbeSnapshot($items);
    }

    private function identityArtifactMatchesNode(
        Node $node,
        NodeIdentityArtifact $artifact,
        string $observedAddress,
    ): bool {
        return (
            $artifact->name === $node->name
            && $artifact->role === $node->displayRole()
            && $artifact->localRole === $node->displayRole()
            && $artifact->status === 'active'
            && $artifact->platform === $node->platform
            && $artifact->wireguardAddress === $node->wireguard_address
            && $observedAddress === $node->wireguard_address
        );
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
                $this->dnsmasqReconciler()->reconcileRecords();

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
                $this->dnsmasqReconciler()->reconcileRecords();

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

        foreach ($this->nodeSecurityPostureProbe()->adopt($node, $snapshot) as $result) {
            $results[] = $result;
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

    private function nodeSecurityPostureProbe(): NodeSecurityPostureProbe
    {
        return $this->nodeSecurityPostureProbe ?? new NodeSecurityPostureProbe($this->localExecutor());
    }

    private function updateDriverRegistry(): UpdateDriverRegistry
    {
        return $this->updateDriverRegistry ?? app(UpdateDriverRegistry::class);
    }

    private function updateTargetFactory(): UpdateTargetFactory
    {
        return $this->updateTargetFactory ?? app(UpdateTargetFactory::class);
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function dnsmasqReconciler(): DnsmasqReconciler
    {
        return $this->dnsmasqReconciler ?? app(DnsmasqReconciler::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(string $output): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
