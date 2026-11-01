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
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleMetricsInput;
use App\Domain\Nodes\NodeRoleParentInput;
use App\Domain\Nodes\NodeRolePlacement;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the real assignment and immutable parent intent before one native start. */
final readonly class AdmitNodeRoleAddAction
{
    public function __construct(private StartOperationAction $operations, private OperationStore $store,
        private OperationIdempotency $idempotency, private AssignRoleAction $assign, private RoleRegistry $roles,
        private NodeRoleParentInput $input) {}

    public function execute(Node $node, RoleName $role, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null, bool $duringProvisioning = false): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_add')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $role, $analytics, $requestId, $duringProvisioning): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if (! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
            }
            if ($role === RoleName::Database && (! is_string($node->wireguard_ip) || $node->wireguard_ip === '')) {
                throw new ResourceOperationException('database.wireguard_ip_missing', 'The database role requires its recorded WireGuard address.', 422);
            }
            $native = NativeNodeRoleConvergeInput::supportsContribution($node, $role);
            if ($duringProvisioning || ! app(ManagedNodeEligibility::class)->allows($node)
                || (! $native && ! app(NodeRolePlacement::class)->allows($node))) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role work requires its implemented209 contribution or its existing managed Node worker.', 422);
            }
            if ($role === RoleName::Analytics) {
                $analytics ??= app(AnalyticsRoleSettingsRepository::class)->find($node);
                if ($analytics === null) {
                    throw new ResourceOperationException('analytics.settings_missing', 'The analytics role requires its storage Processes.', 422);
                }
                app(AnalyticsStorageProcessGuard::class)->assert($analytics->postgresProcessId, $analytics->clickhouseProcessId);
                app(AnalyticsRoleSettingsRepository::class)->store($node, $analytics);
            }
            $this->assign->preflight($node, $role);
            $existing = $node->roles()->where('role', $role)->first();
            if ($existing !== null && ! ($role === RoleName::Ingress && $existing->status === LifecycleStatus::Active)) {
                throw new RoleAssignmentException("Role [{$role->value}] is already assigned; explicit convergence is required.");
            }
            $operation = $this->operations->execute('node.role_add', 'node', $node->id, $node->id,
                ['node_id' => $node->id, 'role' => $role->value, 'converge' => false], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            if (! is_string($node->agent_secret_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $node->agent_secret_hash) !== 1) {
                throw new ResourceOperationException('node.agent_rekey_required', 'The role needs the existing recorded Node agent identity.', 409);
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
            $native = $native && $metrics === null;
            if (! $native && ! app(NodeRolePlacement::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This role set requires its existing Node worker.', 422);
            }
            $captured = $native ? $this->input->contribution($node, $assignment, $snapshot) : null;
            $snapshot['certificates'] = $captured['intent']['certificates'] ?? $snapshot['certificates'];
            $owner = $metrics === null ? $node->id : ValidatedData::integer($metrics['metrics_node_id']);
            $runtime = ValidatedData::object($snapshot['runtime'] ?? []);
            $clickhouse = ValidatedData::object($runtime['clickhouse'] ?? []);
            $this->store->change($operation, ['input' => [...$operation->input,
                '_role' => $snapshot,
                ...($captured === null ? [
                    'metrics_runtime' => $metrics === null ? null : ['$secret' => 'metrics.fleet_runtime', 'id' => $owner],
                    'private_bindings' => ['metrics_runtime' => ['owner_node_id' => $owner, 'read_node_ids' => $metrics === null ? [] : [$owner], 'write_node_ids' => []]],
                    'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
                    'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $node->id],
                ] : ['native_role' => $captured['intent'], 'intent' => ['target' => $captured['intent']],
                    'execution_secrets' => $this->input->executionCopies($operation, $node, $snapshot)]),
                'participant_node_ids' => array_values(array_unique([$node->id, ...($captured['participant_node_ids'] ?? []),
                    ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                    ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])),
                    ...NodeRoleMetricsInput::participants($metrics),
                    ...(isset($clickhouse['node_id']) ? [ValidatedData::integer($clickhouse['node_id'])] : [])]))],
                'state' => [...($operation->state ?? []), 'role_intent' => NodeRoleIntent::capture($assignment, $created)]]);

            return $operation;
        });
    }
}
