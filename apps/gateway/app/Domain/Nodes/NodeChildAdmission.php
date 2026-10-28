<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\StartNodeConvergeAction;
use App\Actions\Nodes\StartNodeNetworkOperationAction;
use App\Actions\Nodes\StartNodeNetworkRollbackAction;
use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Actions\Nodes\StartNodeWireGuardHubOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Uses Node admissions; the kit owns parent stamps, idempotency and inherited target holds. */
final readonly class NodeChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartNodeConvergeAction $footprint, private StartNodePhpPackagesAction $php, private AdmitNodeRoleOperationAction $roles) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if ($intent['kind'] === 'node.network_rollback') {
            if ($intent['node_id'] !== $parent->node_id || $intent['input'] !== []) {
                throw new ResourceOperationException('operation.child_forbidden', 'Peer rollback has no caller-selected payload.', 422);
            }

            return app(StartNodeNetworkRollbackAction::class)->execute($parent);
        }
        if ($intent['kind'] === 'node.network_converge') {
            $roleless = $intent['input']['roleless_operator'] ?? null;
            if (! is_bool($roleless)) {
                throw new ResourceOperationException('operation.child_forbidden', 'The network child requires explicit DNS policy.', 422);
            }

            return app(StartNodeNetworkOperationAction::class)->execute(Node::query()->findOrFail($intent['node_id']), $roleless);
        }
        if ($intent['kind'] === 'node.wireguard_hub_converge') {
            return app(StartNodeWireGuardHubOperationAction::class)->execute($parent, $intent);
        }
        if (in_array($intent['kind'], ['node.role_relocate_source', 'node.role_relocate_target'], true)) {
            return $this->prepared($this->roles->relocationChild($parent, $intent));
        }
        if ($parent->target_type === 'cluster_router' && in_array($intent['kind'], ['node.role_converge', 'node.role_remove'], true)) {
            return $this->prepared($this->roles->routerChild($parent, $intent));
        }
        $node = Node::query()->findOrFail($intent['node_id']);
        $input = $intent['input'];

        if (in_array($intent['kind'], ['node.role_add', 'node.role_remove'], true)) {
            $role = RoleName::tryFrom(ValidatedData::string($input['role'] ?? null));
            if ($role === null) {
                throw new ResourceOperationException('operation.child_forbidden', 'The child has no supported Node role.', 422);
            }
            if ($intent['kind'] === 'node.role_add') {
                $converge = $input['converge'] ?? null;
                if (! is_bool($converge)) {
                    throw new ResourceOperationException('operation.child_forbidden', 'The child must declare convergence explicitly.', 422);
                }

                return $this->prepared($this->roles->add($node, $role, $converge, duringProvisioning: $parent->kind === 'node.add'));
            }
            $purge = $input['purge_data'] ?? null;
            $offline = $input['offline'] ?? null;
            if (! is_bool($purge) || ! is_bool($offline)) {
                throw new ResourceOperationException('operation.child_forbidden', 'The child must declare removal policy explicitly.', 422);
            }
            $operation = $this->roles->remove($node, $role, true, $purge, $offline, ($parent->input['deleted'] ?? false) === true);
            if (! $operation instanceof Operation) {
                throw new ResourceOperationException('operation.child_forbidden', 'The removed role no longer has executable intent.', 409);
            }

            return $this->prepared($operation);
        }
        if ($intent['kind'] === 'node.converge') {
            $force = $input['force_artifacts'] ?? null;
            $firstVisit = $input['first_visit'] ?? null;
            if (! is_bool($force) || ! is_bool($firstVisit)) {
                throw new ResourceOperationException('operation.child_forbidden', 'Footprint child flags must be explicit booleans.', 422);
            }

            return $this->prepared($this->footprint->execute($node, $force, $firstVisit));
        }

        $operation = match ($intent['kind']) {
            'node.prepare_php_packages' => ($input['profile'] ?? null) === 'app-prod'
                ? $this->php->execute($node, ValidatedData::stringList($input['versions']))
                : throw new ResourceOperationException('operation.child_forbidden', 'Only the app-prod PHP package profile is admitted.', 422),
            default => throw new ResourceOperationException('operation.child_forbidden', 'This Node child kind has no owning admission.', 422),
        };

        return $this->prepared($operation);
    }

    private function prepared(Operation $operation): Operation
    {
        $result = match ($operation->kind) {
            'node.converge' => app(PrepareNodeFootprintCommand::class)->run($operation, [
                'force_artifacts' => $operation->input['force_artifacts'], 'first_visit' => $operation->input['first_visit']]),
            'node.prepare_php_packages' => app(PrepareNodePhpPackagesCommand::class)->run($operation, [
                'versions' => $operation->input['versions'], 'profile' => $operation->input['profile']]),
            default => app(PrepareNodeRoleCommand::class)->run($operation, app(NodeRoleOperationExecution::class)->context($operation)['descriptors'][0]['input']),
        };
        if (($result['prepared'] ?? null) !== true) {
            throw new ResourceOperationException(is_string($result['error_code'] ?? null) ? $result['error_code'] : 'operation.preparation_failed',
                'The Node child could not record its immutable preparation.', 409);
        }

        return $operation;
    }
}
