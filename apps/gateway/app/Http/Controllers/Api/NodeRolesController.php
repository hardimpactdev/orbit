<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\ListNodeRolesAction;
use App\Actions\Nodes\RelocateNodeRoleAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Data\Nodes\NodeRoleAssignmentData;
use App\Data\Nodes\NodeRoleMutationData;
use App\Data\Operations\OperationData;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleFollowUpReport;
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
        AdmitNodeRoleOperationAction $action,
        AddNodeRoleAction $unported,
        RoleRegistry $registry,
    ): JsonResponse {
        $role = $request->role();
        $this->guardMutable($role, $registry);

        try {
            if (NodeRoleDirectInput::supports($role)) {
                $result = $action->add($node, $role, $request->convergeExisting(), $request->analyticsSettings(),
                    requestId: $request->attributes->getString('orbit.request_id'), native: true);
            } else {
                $result = $unported->execute($node, $role, $request->convergeExisting(), $request->analyticsSettings());

                return response()->json([
                    'data' => NodeRoleMutationData::added($node, $result['assignment'], $result['follow_up'])->toArray(),
                    'meta' => $this->meta($request),
                ], $result['created'] ? 201 : 200);
            }
        } catch (RoleAssignmentException $exception) {
            throw new NodeRoleValidationException(
                message: $exception->getMessage(),
                details: ['field' => 'role', 'role' => $role->value],
            );
        }

        return response()->json(
            [
                'data' => OperationData::fromModel($result)->toArray(),
                'meta' => $this->meta($request),
            ],
            202,
        );
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function relocate(
        RelocateNodeRoleRequest $request,
        Node $node,
        RelocateNodeRoleAction $action,
        NodeRoleFollowUpReport $followUps,
    ): JsonResponse {
        $followUps->take();
        $outcome = $action->execute($node, $request->role(), $request->force(), $request->from());

        return response()->json([
            'data' => NodeRoleMutationData::added($node, $outcome, $followUps->take())->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RoleMutation)]
    public function destroy(
        RemoveNodeRoleRequest $request,
        Node $node,
        RemoveNodeRoleAction $action,
        RoleRegistry $registry,
    ): JsonResponse {
        $validatedRole = $request->role();
        $this->guardMutable($validatedRole, $registry);
        try {
            $outcome = $action->execute(
                $node,
                $validatedRole,
                $request->force(),
                $request->purgeData(),
                $request->offline(),
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
            'data' => NodeRoleMutationData::removed($node, $validatedRole, $outcome)->toArray(),
            'meta' => $this->meta($request),
        ]);
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
