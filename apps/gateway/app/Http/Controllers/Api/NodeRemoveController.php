<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\NodeRemovalFailed;
use App\Actions\Nodes\RemoveNode;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\RemoveNodeApiRequest;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('node:remove', servingNode: ServingNode::Target)]
final class NodeRemoveController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityTargetName = null;

    private bool $activityRemovedSelf = false;

    private int $activityGrantsRemoved = 0;

    private bool $activityWireGuardPeerRemoved = false;

    private bool $activityRemovalCompleted = false;

    public function __construct(
        private readonly RemoveNode $removeNode,
        private readonly NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(RemoveNodeApiRequest $request, string $name): JsonResponse
    {
        $this->activityTargetName = $name;

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node) {
            return $this->error(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: ['name' => $name],
                status: 404,
            );
        }

        $this->activitySubject = $node;

        if ($this->nodeRoleAssignments->nodeIsGateway($node)) {
            return $this->error(
                code: 'node.gateway_removal_denied',
                message: 'The gateway node cannot be removed with this command.',
                meta: [
                    'name' => $name,
                    'role' => 'gateway',
                ],
                status: 422,
            );
        }

        $removedSelf = $caller->name === $node->name;
        $this->activityRemovedSelf = $removedSelf;

        try {
            $dto = $this->removeNode->handle($node, $removedSelf);
        } catch (NodeRemovalFailed $exception) {
            $this->activityWireGuardPeerRemoved = ($exception->meta['wireguard_peer_removed'] ?? false) === true;

            return $this->error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
                status: $exception->status,
            );
        }

        $this->activityGrantsRemoved = $dto->grantsRemoved;
        $this->activityWireGuardPeerRemoved = $dto->wireguardPeerRemoved;
        $this->activityRemovalCompleted = true;

        $success = [
            'data' => [
                'name' => $dto->name,
                'action' => 'removed',
                'removed_self' => $dto->removedSelf,
                'wireguard_peer_removed' => $dto->wireguardPeerRemoved,
                'grants_removed' => $dto->grantsRemoved,
            ],
        ];

        if ($dto->warnings !== []) {
            $success['meta'] = [
                'warnings' => $dto->warnings,
            ];
        }

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error(
            code: 'authorization_failed',
            message: $message,
            meta: $meta,
            status: 403,
        );
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
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /nodes/{name}';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return [
            'target_node' => $this->activityTargetName ?? (string) request()->route('name'),
            'removed_self' => $this->activityRemovedSelf,
            'grants_removed' => $this->activityGrantsRemoved,
            'wireguard_peer_removed' => $this->activityWireGuardPeerRemoved,
        ];
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('name');

        if ($target === '') {
            return null;
        }

        return $this->activityRemovalCompleted
            ? "Node {$target} removed"
            : "Node {$target} removal failed";
    }
}
