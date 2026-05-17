<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Requests\Api\NodeRoleListApiRequest;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class NodeRoleListController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(NodeRoleListApiRequest $request, string $name): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $authorization = $this->authorizeCaller($caller, 'list node roles');
        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $node = Node::query()
            ->with('roleAssignments')
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->error('node.not_found', "Node '{$name}' not found.", ['name' => $name], 404);
        }

        $this->activitySubject = $node;

        return response()->json([
            'success' => [
                'data' => [
                    'node' => $node->name,
                    'roles' => $node->roleAssignments
                        ->map(fn ($assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                        ->all(),
                ],
            ],
        ]);
    }

    private function authorizeCaller(Node $caller, string $verb): ?JsonResponse
    {
        if ($caller->role === 'gateway') {
            return null;
        }

        $gateway = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->orderBy('name')
            ->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                "This caller is not authorized to {$verb}.",
                ['required_node' => null, 'caller_role' => $caller->role],
            );
        }

        $hasGatewayAccess = DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $gateway->id)
            ->exists();

        if ($hasGatewayAccess) {
            return null;
        }

        return $this->authorizationFailed(
            "This caller is not authorized to {$verb}.",
            ['required_node' => $gateway->name, 'caller_role' => $caller->role],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error('authorization_failed', $message, $meta, 403);
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'node.role.listed';
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
        return [
            'node' => (string) request()->route('name'),
        ];
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
