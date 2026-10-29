<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Database-only enrollment transitions under the stamped ancestor hold. */
final readonly class NodeEnrollmentStateCommand implements OperationGatewayCommand
{
    public function __construct(private OperationChildren $children, private NodeEnrollmentEvidence $evidence,
        private ActiveTldScopeGuard $tlds, private RouteMutationReconciler $routes, private VpnConfigurationRepository $vpn) {}

    public function schema(): array
    {
        return ['anyOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'array', 'maxItems' => 0]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $parent = Operation::query()->findOrFail($operation->parent_operation_id);
        $this->children->verify($parent, $operation);
        $intent = ValidatedData::object($operation->state['enrollment_intent'] ?? []);
        $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
        if ($parent->kind !== 'node.add' || $parent->node_id !== $node->id || ($intent['id'] ?? null) !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The enrollment stage has no retained Node ownership.', 422);
        }
        if ($operation->kind === 'node.enrollment_restore') {
            $this->children->requireSucceeded($parent, 'add-begin');
            $prior = ValidatedData::object($intent['prior_state'] ?? null);
            $currentArchitecture = $node->architecture;
            if ($prior['architecture'] === null && $currentArchitecture !== null) {
                $prior['architecture'] = $currentArchitecture;
            }
            if (($intent['route_changes'] ?? []) !== []) {
                $candidate = ValidatedData::object($intent['candidate']);
                $prior['tld'] = $candidate['tld'];
                $prior['cluster_id'] = $candidate['cluster_id'];
            }
            $key = $prior['wireguard_public_key'] ?? null;
            if (is_string($key)) {
                $this->vpn->assignPeerKey($node, $key, function () use ($node, $prior): void {
                    $node->update($prior);
                });
            } else {
                $node->update($prior);
            }

            return ['restoring' => true];
        }
        $this->evidence->before($parent, $operation->child_key ?? '');
        $candidate = ValidatedData::object($intent['candidate']);
        $tld = ValidatedData::nullableString($candidate['tld']);
        $clusterId = $candidate['cluster_id'] === null ? null : ValidatedData::integer($candidate['cluster_id']);
        $this->tlds->assertNodeTldAvailable($node, $tld, $clusterId);
        $this->routes->reconcile(nodeOverrides: [$node->id => ['tld' => $tld, 'cluster_id' => $clusterId]],
            baselineNodeOverrides: [$node->id => ['tld' => $node->tld, 'cluster_id' => $node->cluster_id]]);
        if ($operation->kind === 'node.enrollment_begin') {
            $prior = is_array($intent['prior_state'] ?? null) ? $intent['prior_state'] : [];
            if (($prior['status'] ?? null) === 'active') {
                $candidate['user'] = $prior['user'];
            }
            $node->update([...$candidate, 'status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);

            return ['preparing' => true];
        }
        if ($operation->kind !== 'node.enrollment_activate'
            || ($parent->input['mode'] !== 'tld_only' && $node->status !== LifecycleStatus::Provisioning)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The enrollment has not reached activation.', 409);
        }
        unset($candidate['architecture']);
        $node->update([...$candidate, 'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);

        return ['activated' => true];
    }
}
