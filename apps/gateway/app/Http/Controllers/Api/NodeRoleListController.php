<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\NodeRoleListApiRequest;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('role:read', servingNode: ServingNode::Target)]
final class NodeRoleListController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(NodeRoleListApiRequest $request, string $name): JsonResponse
    {
        $node = Node::query()
            ->with('roleAssignments')
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node) {
            return $this->error('node.not_found', "Node '{$name}' not found.", ['name' => $name], 404);
        }

        $this->activitySubject = $node;

        return response()->json([
            'success' => [
                'data' => [
                    'node' => $node->name,
                    'roles' => $node
                        ->roleAssignments
                        ->map(fn ($assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                        ->all(),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'node.role.listed';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return [
            'node' => (string) request()->route('name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
