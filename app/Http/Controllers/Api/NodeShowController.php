<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeAgentIdeDefaults;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

final class NodeShowController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(string $name): JsonResponse
    {
        $node = Node::query()
            ->with('roleAssignments')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $node instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node '{$name}' not found or not visible.",
                    'meta' => [
                        'name' => $name,
                    ],
                ],
            ], 404);
        }

        $this->activitySubject = $node;

        return response()->json([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => $node->name,
                        'role' => $node->role,
                        'status' => $node->status,
                        'environment' => app(NodeRoleAssignments::class)->activeAppHostEnvironment($node),
                        'platform' => $node->platform ?? 'unknown',
                        'roles' => $node->roleAssignments
                            ->map(fn (NodeRoleAssignment $assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                            ->all(),
                        'addresses' => [
                            'wireguard' => $node->wireguard_address ?? $node->host,
                        ],
                        'agent_ide' => NodeAgentIdeDefaults::payloadFor($node),
                        'grants' => [
                            'consuming_nodes' => $node->consumingNodes()->pluck('name')->all(),
                            'serving_nodes' => $node->servingNodes()->pluck('name')->all(),
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /nodes/{name}';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
