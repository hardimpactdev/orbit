<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NativeNodeRoleConvergeInput;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleMetricsInput;
use App\Domain\Nodes\NodeRolePlacement;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\NodeRoleRemoveInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Claims and records role intent atomically; it never installs or publishes a runtime. */
final readonly class AdmitNodeRoleOperationAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationStore $store,
        private OperationIdempotency $idempotency,
        private AssignRoleAction $assign,
        private RoleRegistry $roles,
        private AnalyticsRoleSettingsRepository $analytics,
        private AnalyticsStorageProcessGuard $analyticsStorage,
        private RouteRemovalGuard $routes,
    ) {}

    public function add(Node $node, RoleName $role, bool $converge = false, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null, bool $duringProvisioning = false): Operation
    {
        if (! $converge) {
            return app(AdmitNodeRoleAddAction::class)->execute($node, $role, $analytics, $requestId, $duringProvisioning);
        }
        $kind = 'node.role_converge';
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $role, $analytics, $requestId, $duringProvisioning, $kind): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($role === RoleName::Database && (! is_string($node->wireguard_ip) || $node->wireguard_ip === '')) {
                throw new ResourceOperationException('database.wireguard_ip_missing', 'The database role requires its recorded WireGuard address.', 422);
            }
            $native = NativeNodeRoleConvergeInput::supports($node, $role);
            if ($role === RoleName::WebSocket && ! $native) {
                throw new ResourceOperationException('operation.kind_unavailable', 'WebSocket convergence requires a supported native role plan.', 422);
            }
            if ($duringProvisioning || ! app(ManagedNodeEligibility::class)->allows($node)
                || (! $native && ! app(NodeRolePlacement::class)->allows($node))) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role work requires its exact managed Node worker.', 422);
            }
            if (! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
            }
            $this->assign->preflight($node, $role);
            if (! is_string($node->agent_secret_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $node->agent_secret_hash) !== 1) {
                throw new ResourceOperationException('node.agent_rekey_required', 'The role needs the existing recorded Node agent identity.', 409);
            }
            if ($role === RoleName::Analytics) {
                $analytics ??= $this->analytics->find($node);
                if ($analytics === null) {
                    throw new ResourceOperationException('analytics.settings_missing', 'The analytics role requires its storage Processes.', 422);
                }
                $this->analyticsStorage->assert($analytics->postgresProcessId, $analytics->clickhouseProcessId);
                $this->analytics->store($node, $analytics);
            }
            $operation = $this->operations->execute($kind, 'node', $node->id, $node->id,
                ['node_id' => $node->id, 'role' => $role->value, 'converge' => true], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $assignment = $this->assign->execute($node, $role);
            $created = $assignment->wasRecentlyCreated;
            if (! $created) {
                $assignment->claimConvergence();
            }
            if ($role === RoleName::Gateway) {
                app(GrantGatewayRoleAccessAction::class)->execute($node);
            }
            app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
            $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created);
            if (ValidatedData::object($snapshot['footprint'])['refusal'] !== null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'An applicable footprint contribution has no native action.', 422);
            }
            $metrics = $snapshot['metrics_fleet'] === null ? null : ValidatedData::object($snapshot['metrics_fleet']);
            if ($native && $role === RoleName::WebSocket && $metrics !== null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'WebSocket convergence requires the native Metrics fleet contribution.', 422);
            }
            $native = $native && $metrics === null;
            if (! $native && ! app(NodeRolePlacement::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This role set requires its existing Node worker.', 422);
            }
            $metricsOwner = $metrics === null ? $node->id : ValidatedData::integer($metrics['metrics_node_id']);
            $runtime = ValidatedData::object($snapshot['runtime'] ?? []);
            $clickhouse = ValidatedData::object($runtime['clickhouse'] ?? []);
            $nativeInput = $native ? app(NativeNodeRoleConvergeInput::class)->capture($node, $assignment, $snapshot) : null;
            $executionSecrets = [];
            if ($nativeInput !== null) {
                $private = ['observer' => new OperationSecretValue($node->agent_secret_hash)];
                if ($role === RoleName::WebSocket) {
                    $material = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $node->id);
                    $private['runtime_material'] = new OperationSecretValue(json_encode($material->value, JSON_THROW_ON_ERROR));
                }
                foreach ($private as $name => $value) {
                    $executionSecrets[$name] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                        'node-role-'.$operation->id.'-'.$name,
                        ValidatedData::string($value->value));
                }
            }
            $this->store->change($operation, ['input' => [...$operation->input, '_role' => $snapshot,
                ...($nativeInput === null ? [] : ['native_role' => $nativeInput['intent'], 'execution_secrets' => $executionSecrets]),
                'participant_node_ids' => array_values(array_unique([$node->id,
                    ...($nativeInput['participant_node_ids'] ?? []),
                    ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                    ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])),
                    ...NodeRoleMetricsInput::participants($metrics),
                    ...(isset($clickhouse['node_id']) ? [ValidatedData::integer($clickhouse['node_id'])] : [])])),
                'metrics_runtime' => $snapshot['metrics_fleet'] === null ? null : ['$secret' => 'metrics.fleet_runtime', 'id' => $metricsOwner],
                'private_bindings' => ['metrics_runtime' => [
                    'owner_node_id' => $metricsOwner, 'read_node_ids' => $snapshot['metrics_fleet'] === null ? [] : [$metricsOwner], 'write_node_ids' => []]],
                'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
                'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $node->id]]]);

            return $operation;
        });
    }

    public function remove(Node $node, RoleName $role, bool $force = false, bool $purgeData = false, bool $offline = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_remove')) !== null) {
            return $existing;
        }
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to remove this node role.', [
                'field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value,
            ]);
        }

        return DB::transaction(function () use ($node, $role, $purgeData, $offline, $deleted, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $this->guardMutable($node, $role);
            if (! app(ManagedNodeEligibility::class)->allows($node) || ! app(NodeRolePlacement::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role removal requires its exact managed Node worker.', 422);
            }
            $this->routes->assertRoleRemovable($node, $role);
            $assignment = $node->roles()->where('role', $role)->lockForUpdate()->firstOrFail();
            if ($role === RoleName::AppDev && $node->instances()->exists()) {
                throw new NodeRoleValidationException('The app-dev role cannot be removed while its Node owns Instances.', [
                    'reason' => 'instances_attached', 'role' => $role->value,
                ]);
            }
            $operation = $this->operations->execute('node.role_remove', 'node', $node->id, $node->id,
                ['node_id' => $node->id, 'role' => $role->value, 'purge_data' => $purgeData, 'offline' => $offline], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $assignment->claimRemoval();
            $snapshot = app(NodeRoleRemoveInput::class)->capture($node, $assignment, $purgeData, $offline, $deleted);
            $this->bindRemoval($operation, $snapshot);

            return $operation;
        });
    }

    public function relocate(Node $target, RoleName $role, bool $force = false, ?Node $from = null, ?string $requestId = null): Operation
    {
        return app(AdmitNodeRoleRelocateAction::class)->execute($target, $role, $force, $from, $requestId);
    }

    /** @param array<string,mixed> $snapshot */
    private function bindRemoval(Operation $operation, array $snapshot): void
    {
        $metrics = $snapshot['metrics_fleet'] === null ? null : ValidatedData::object($snapshot['metrics_fleet']);
        $owner = $metrics === null ? $operation->node_id : ValidatedData::integer($metrics['metrics_node_id']);
        $this->store->change($operation, ['input' => [...$operation->input, '_role_remove' => $snapshot,
            'participant_node_ids' => array_values(array_unique([$operation->node_id,
                ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])),
                ...NodeRoleMetricsInput::participants($metrics)])),
            'metrics_runtime' => $metrics === null ? null : ['$secret' => 'metrics.fleet_runtime', 'id' => $owner],
            'private_bindings' => ['metrics_runtime' => ['owner_node_id' => $owner, 'read_node_ids' => $metrics === null ? [] : [$owner], 'write_node_ids' => []]]]]);
    }

    private function guardMutable(Node $node, RoleName $role): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }
        if (! $this->roles->definition($role)->mutable) {
            throw new NodeRoleValidationException("Role [{$role->value}] is protected from generic mutation.");
        }
    }
}
