<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\NodeRoleAssignment;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use App\Services\S3\S3RouteRegistrar;
use App\Services\Support\GatewayActionResult;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class NodeBootstrapCompletion
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private NodeBootstrapCompletionLock $completionLock,
        private S3RouteRegistrar $s3RouteRegistrar,
        private DnsmasqReconciler $dnsmasqReconciler,
        private ProvisioningAgentReadinessProbe $readinessProbe,
        private NodeRoleAssignmentService $roleAssignmentService,
        private NodeRoleRegistry $roleRegistry,
        private NodeConverger $nodeConverger,
        private NodeAgentProvisioning $agentProvisioning,
        private NodeSecurityBaseline $securityBaseline,
    ) {}

    /** @param Closure(NodeBootstrap): GatewayActionResult $converge */
    public function complete(
        NodeBootstrap $bootstrap,
        Node $caller,
        Closure $converge,
    ): NodeBootstrapCompletionResult {
        try {
            return $this->completionLock->synchronized(
                $bootstrap->id,
                fn (): NodeBootstrapCompletionResult => $this->completeWhileLocked(
                    $bootstrap,
                    $caller,
                    $converge,
                ),
            );
        } catch (LockTimeoutException) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'Node bootstrap completion is already in progress; retry shortly.',
                    meta: [
                        'bootstrap_id' => $bootstrap->id,
                        'step' => 'completion_lock',
                    ],
                ),
                completedNow: false,
            );
        }
    }

    /**
     * @param  list<string>  $roles
     * @mago-expect lint:excessive-parameter-list
     */
    public function convergePrepared(
        string $name,
        array $roles,
        WorkloadNodeProvisioningInput $inputs,
        ?int $appProductionIngressNodeId,
        NodeBootstrap $bootstrap,
        NodeCreationInput $input,
    ): GatewayActionResult {
        $roleSelectionFailure = $this->prevalidateWorkloadRoles($roles);

        if ($roleSelectionFailure instanceof GatewayActionResult) {
            return $roleSelectionFailure;
        }

        $node = Node::query()->find($bootstrap->node_id);

        if (! $node instanceof Node || $node->name !== $name) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: 'Pending bootstrap identity does not match the requested node.',
                meta: ['name' => $name],
            );
        }

        try {
            $this->readinessProbe->waitUntilReady($node);
        } catch (RuntimeException $exception) {
            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' Agent is not ready through WireGuard.",
                meta: [
                    'node' => $name,
                    'step' => 'agent_readiness',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $roleAssignmentFailure = $this->ensureInitialWorkloadRoles(
            node: $node,
            roles: $roles,
            appProductionIngressNodeId: $appProductionIngressNodeId,
            backingNodeIds: [
                'postgres' => $inputs->postgresNodeId,
                'postgres_process' => $inputs->postgresProcessId,
                'clickhouse' => $inputs->clickhouseNodeId,
            ],
            input: $input,
        );

        if ($roleAssignmentFailure instanceof GatewayActionResult) {
            return $roleAssignmentFailure;
        }

        $warnings = [];

        if (in_array(NodeRoleName::Agent->value, $roles, true)) {
            $agentSetupFailure = $this->agentProvisioning->apply($node, $input, $warnings);

            if ($agentSetupFailure instanceof GatewayActionResult) {
                return $agentSetupFailure;
            }
        }

        $nodeSetup = $this->setupManagedNode($node, $roles);

        if ($nodeSetup instanceof GatewayActionResult) {
            return $nodeSetup;
        }

        $securityBaselineFailure = $this->securityBaseline->apply($node);

        if ($securityBaselineFailure instanceof GatewayActionResult) {
            return $securityBaselineFailure;
        }

        return GatewayActionResult::success(
            $this->completedNodePayload($node, $inputs->host, $roles),
            $warnings !== [] ? ['warnings' => $warnings] : [],
        );
    }

    /** @param list<string> $roles */
    private function prevalidateWorkloadRoles(array $roles): ?GatewayActionResult
    {
        foreach ($roles as $role) {
            if ($this->roleRegistry->roleIsEligibleForWorkloadNodeCreation($role)) {
                continue;
            }

            $exception = NodeCreationRoleInputException::unsupportedWorkloadRole();

            return GatewayActionResult::error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        $conflictingPair = $this->roleRegistry->firstConflictingRolePair($roles);

        if ($conflictingPair === null) {
            return null;
        }

        $exception = NodeCreationRoleInputException::conflictingWorkloadRoles($conflictingPair);

        return GatewayActionResult::error(
            code: $exception->errorCode,
            message: $exception->getMessage(),
            meta: $exception->meta,
        );
    }

    /**
     * @param Closure(NodeBootstrap): GatewayActionResult $converge
     */
    private function completeWhileLocked(
        NodeBootstrap $bootstrap,
        Node $caller,
        Closure $converge,
    ): NodeBootstrapCompletionResult {
        $bootstrap->refresh();
        $node = Node::query()->find($bootstrap->node_id);

        if ($bootstrap->initiating_node_id !== $caller->id) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'authorization_failed',
                    message: 'Only the initiating node can complete this bootstrap.',
                    meta: ['bootstrap_id' => $bootstrap->id],
                ),
                completedNow: false,
            );
        }

        if ($bootstrap->status === 'completed' && $node instanceof Node && $node->isActive()) {
            $this->syncActiveS3ServiceRoute($node);
            $this->dnsmasqReconciler->reconcileRecords();

            return new NodeBootstrapCompletionResult(
                result: $this->completedBootstrapResult($bootstrap, $node),
                completedNow: false,
            );
        }

        if ($bootstrap->status !== 'pending' || ! $node instanceof Node || ! $node->isProvisioning()) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.incompatible',
                    message: 'Node bootstrap is not in a compatible pending state.',
                    meta: ['bootstrap_id' => $bootstrap->id],
                ),
                completedNow: false,
            );
        }

        try {
            $result = $converge($bootstrap);
        } catch (Throwable $exception) {
            $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

            if ($completed instanceof GatewayActionResult) {
                return new NodeBootstrapCompletionResult($completed, false);
            }

            if ($bootstrap->status === 'pending') {
                $bootstrap->forceFill([
                    'last_error' => [
                        'code' => 'node.provisioning_incomplete',
                        'message' => $exception->getMessage(),
                    ],
                ])->save();
            }

            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'Node bootstrap completion failed.',
                    meta: [
                        'bootstrap_id' => $bootstrap->id,
                        'error' => $exception->getMessage(),
                    ],
                ),
                completedNow: false,
            );
        }

        if ($result->successful()) {
            return $this->commitSuccessfulCompletion($bootstrap, $node, $result);
        }

        $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

        if ($completed instanceof GatewayActionResult) {
            return new NodeBootstrapCompletionResult($completed, false);
        }

        if ($bootstrap->status === 'pending') {
            $bootstrap->forceFill([
                'last_error' => is_array($result->payload['error'] ?? null) ? $result->payload['error'] : null,
            ])->save();
        }

        return new NodeBootstrapCompletionResult($result, false);
    }

    private function commitSuccessfulCompletion(
        NodeBootstrap $bootstrap,
        Node $node,
        GatewayActionResult $result,
    ): NodeBootstrapCompletionResult {
        try {
            /** @var bool $completedNow */
            $completedNow = DB::transaction(static function () use ($bootstrap, $node): bool {
                $transitioned = NodeBootstrap::query()
                    ->whereKey($bootstrap->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'completed',
                        'last_error' => null,
                    ]);

                if ($transitioned !== 1) {
                    return false;
                }

                $node->forceFill(['status' => NodeStatus::Active])->save();

                return true;
            });
        } catch (Throwable $exception) {
            $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

            if ($completed instanceof GatewayActionResult) {
                return new NodeBootstrapCompletionResult($completed, false);
            }

            if ($bootstrap->status === 'pending') {
                $bootstrap->forceFill([
                    'last_error' => [
                        'code' => 'node.provisioning_incomplete',
                        'message' => $exception->getMessage(),
                    ],
                ])->save();
            }

            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'Node bootstrap terminal state could not be committed.',
                    meta: [
                        'bootstrap_id' => $bootstrap->id,
                        'error' => $exception->getMessage(),
                    ],
                ),
                completedNow: false,
            );
        }

        if (! $completedNow) {
            $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

            if ($completed instanceof GatewayActionResult) {
                return new NodeBootstrapCompletionResult($completed, false);
            }

            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.incompatible',
                    message: 'Node bootstrap completion lost its pending transition.',
                    meta: ['bootstrap_id' => $bootstrap->id],
                ),
                completedNow: false,
            );
        }

        $this->syncActiveS3ServiceRoute($node);
        $this->dnsmasqReconciler->reconcileRecords();

        return new NodeBootstrapCompletionResult($result, true);
    }

    private function syncActiveS3ServiceRoute(Node $node): void
    {
        $hasActiveS3Role = $node
            ->roleAssignments()
            ->where('role', NodeRoleName::S3->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();

        if ($hasActiveS3Role) {
            $this->s3RouteRegistrar->syncServiceRoute();
        }
    }

    private function refreshCompletedBootstrap(
        NodeBootstrap $bootstrap,
        Node $node,
    ): ?GatewayActionResult {
        $bootstrap->refresh();
        $node->refresh();

        if ($bootstrap->status === 'completed' && $node->isActive()) {
            return $this->completedBootstrapResult($bootstrap, $node);
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function completedNodePayload(Node $node, string $host, array $roles): array
    {
        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $node->name,
                'tld' => $node->tld,
                'platform' => $node->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $node->wireguard_address,
                ],
                'status' => 'active',
            ],
            'roles' => $node
                ->roleAssignments()
                ->get()
                ->map(fn (NodeRoleAssignment $assignment): array => [
                    'role' => $assignment->role,
                    'status' => $assignment->status->value,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ])
                ->values()
                ->all(),
            'provisioning' => [
                'transport' => 'client-ssh',
                'host' => $host,
                'status' => 'complete',
            ],
            'next_steps' => [],
        ];

        if ($this->containsDevelopmentAppRole($roles)) {
            $payload['development_tld'] = [
                'tld' => $node->tld,
                'gateway_dns' => [
                    'domain' => "*.{$node->tld}",
                    'target' => $node->wireguard_address,
                    'status' => 'configured',
                ],
            ];
        }

        return $payload;
    }

    private function completedBootstrapResult(NodeBootstrap $bootstrap, Node $node): GatewayActionResult
    {
        $roles = array_values(array_filter(
            $node->roleAssignments()->pluck('role')->all(),
            is_string(...),
        ));
        /** @var mixed $requestHost */
        $requestHost = $bootstrap->request['--host'] ?? $node->host;
        $host = is_string($requestHost) && $requestHost !== '' ? $requestHost : $node->host;

        return GatewayActionResult::success($this->completedNodePayload($node, $host, $roles));
    }

    /**
     * @param  list<string>  $roles
     * @param  array{postgres?: int|null, postgres_process?: int|null, clickhouse?: int|null}  $backingNodeIds
     */
    private function ensureInitialWorkloadRoles(
        Node $node,
        array $roles,
        NodeCreationInput $input,
        ?int $appProductionIngressNodeId = null,
        array $backingNodeIds = [],
    ): ?GatewayActionResult {
        foreach ($this->orderWorkloadRoles($roles) as $role) {
            $existingAssignment = $node->roleAssignments()->where('role', $role)->first();
            $settings = $role === NodeRoleName::AppProduction->value
                ? ['ingress_node_id' => $appProductionIngressNodeId ?? $node->id]
                : $this->settingsForRole(
                    $role,
                    $backingNodeIds['postgres'] ?? null,
                    $backingNodeIds['postgres_process'] ?? null,
                    $backingNodeIds['clickhouse'] ?? null,
                    $input->stringOption('s3-data-path') ?? S3RoleSettings::DefaultDataPath,
                );

            $assignment = $existingAssignment instanceof NodeRoleAssignment
                ? $this->roleAssignmentService->retryDuringCreation($node, $role, $settings)
                : $this->roleAssignmentService->addDuringCreation($node, $role, $settings);

            if ($assignment->status !== NodeRoleStatus::Error) {
                continue;
            }

            return GatewayActionResult::error(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but workload role '{$assignment->role}' failed to converge.",
                meta: [
                    'node' => $node->name,
                    'role' => $assignment->role,
                    'status' => $assignment->status->value,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ],
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForRole(
        string $role,
        ?int $postgresNodeId = null,
        ?int $postgresProcessId = null,
        ?int $clickhouseNodeId = null,
        ?string $s3DataPath = null,
    ): array {
        if ($role === NodeRoleName::Analytics->value) {
            return [
                'postgres_node_id' => $postgresNodeId,
                'postgres_process_id' => $postgresProcessId,
                'clickhouse_node_id' => $clickhouseNodeId,
            ];
        }

        if ($role === NodeRoleName::S3->value) {
            return ['data_path' => $s3DataPath ?? S3RoleSettings::DefaultDataPath];
        }

        return [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function setupManagedNode(Node $node, array $roles): ?GatewayActionResult
    {
        if (! $this->containsDevelopmentAppRole($roles)) {
            return null;
        }

        $freshNode = $node->fresh();
        $result = $this->nodeConverger->converge(
            node: $freshNode instanceof Node ? $freshNode : $node,
            context: NodeConvergenceContext::Setup,
            families: ['node', 'tool'],
        );

        if ($result->successful()) {
            return null;
        }

        return GatewayActionResult::error(
            code: 'node.provisioning_incomplete',
            message: "Node '{$node->name}' created but managed setup did not complete.",
            meta: [
                'node' => $node->name,
                'step' => 'node_setup',
                'setup' => $result->toArray(),
            ],
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function orderWorkloadRoles(array $roles): array
    {
        usort(
            $roles,
            static fn (string $first, string $second): int => (
                match ($first) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                } <=> match ($second) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                }
            ),
        );

        return $roles;
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsDevelopmentAppRole(array $roles): bool
    {
        return in_array(NodeRoleName::AppDevelopment->value, $roles, true);
    }
}
