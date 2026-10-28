<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Actions\Nodes\ListNodeRolesAction;
use App\Actions\Nodes\RelocateNodeRoleAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Data\Nodes\NodeRoleAssignmentData;
use App\Data\Nodes\NodeRoleMutationData;
use App\Data\Operations\OperationData;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\AddNodeRoleRequest;
use App\Http\Requests\Nodes\RelocateNodeRoleRequest;
use App\Http\Requests\Nodes\RemoveNodeRoleRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeRolesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    public function index(Request $request, Node $node, ListNodeRolesAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute($node)
                ->map(
                    static fn (NodeRole $assignment): array => NodeRoleAssignmentData::fromModel(
                        $assignment,
                    )->toArray(),
                )
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RoleMutation)]
    #[StartsOperation('node.role_add')]
    public function store(
        AddNodeRoleRequest $request,
        Node $node,
        AddNodeRoleAction $action,
        RoleRegistry $registry,
    ): JsonResponse {
        $role = $request->role();
        $this->guardMutable($role, $registry);

        try {
            $operation = $action->start($node, $role, $request->convergeExisting(), $request->analyticsSettings(), $request->attributes->getString('orbit.request_id'));
        } catch (RoleAssignmentException $exception) {
            throw new NodeRoleValidationException(
                message: $exception->getMessage(),
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        return response()->json(
            [
                'data' => OperationData::fromModel($operation)->toArray(),
                'meta' => $this->meta($request),
            ],
            202,
        );
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    #[StartsOperation('node.role_relocate')]
    public function relocate(
        RelocateNodeRoleRequest $request,
        Node $node,
        RelocateNodeRoleAction $action,
    ): JsonResponse {
        $operation = $action->start($node, $request->role(), $request->force(), $request->from(), $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[RequiresNodeAccess(ServingNode::RoleMutation)]
    #[StartsOperation('node.role_remove')]
    public function destroy(
        RemoveNodeRoleRequest $request,
        Node $node,
        RemoveNodeRoleAction $action,
        RoleRegistry $registry,
    ): JsonResponse {
        $validatedRole = $request->role();
        $this->guardMutable($validatedRole, $registry);
        try {
            $outcome = $action->start(
                $node,
                $validatedRole,
                $request->force(),
                $request->purgeData(),
                $request->offline(),
                requestId: $request->attributes->getString('orbit.request_id'),
            );
        } catch (NodeRoleValidationException $exception) {
            if ($exception->details !== []) {
                throw $exception;
            }

            throw new NodeRoleValidationException(
                message: $exception->getMessage(),
                details: ['field' => 'role', 'role' => $validatedRole->value],
            );
        }

        return response()->json([
            'data' => $outcome instanceof Operation ? OperationData::fromModel($outcome)->toArray() : NodeRoleMutationData::removed($node, $validatedRole, $outcome)->toArray(),
            'meta' => $this->meta($request),
        ], $outcome instanceof Operation ? 202 : 200);
    }

    private function guardMutable(RoleName $role, RoleRegistry $registry): void
    {
        if ($registry->definition($role)->mutable) {
            return;
        }

        throw new NodeRoleValidationException(
            message: "Role [{$role->value}] is protected from generic mutation.",
            details: ['field' => 'role', 'role' => $role->value],
        );
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
