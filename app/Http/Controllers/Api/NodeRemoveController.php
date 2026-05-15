<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\RemoveNode;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Requests\Api\RemoveNodeApiRequest;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class NodeRemoveController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityTargetName = null;

    private bool $activityRemovedSelf = false;

    private int $activityGrantsRemoved = 0;

    private bool $activityWireGuardPeerRemoved = false;

    public function __construct(private readonly RemoveNode $removeNode) {}

    public function __invoke(RemoveNodeApiRequest $request, string $name): JsonResponse
    {
        $this->activityTargetName = $name;

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($caller->role === 'app') {
            return $this->error(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
                status: 403,
            );
        }

        if ($caller->role === 'control') {
            $authorization = $this->authorizeControlCaller($caller);

            if ($authorization instanceof JsonResponse) {
                return $authorization;
            }
        }

        if (! in_array($caller->role, ['control', 'gateway'], true)) {
            return $this->error(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $caller->role],
                status: 403,
            );
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
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

        if ($node->role === 'gateway') {
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

        $dto = $this->removeNode->handle($node, $removedSelf);
        $this->activityGrantsRemoved = $dto->grantsRemoved;
        $this->activityWireGuardPeerRemoved = $dto->wireguardPeerRemoved;

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

    private function authorizeControlCaller(Node $caller): ?JsonResponse
    {
        $gateway = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->orderBy('name')
            ->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                message: 'This control node is not authorized to remove nodes.',
                meta: [
                    'required_node' => null,
                    'caller_role' => 'control',
                ],
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
            message: 'This control node is not authorized to remove nodes.',
            meta: [
                'required_node' => $gateway->name,
                'caller_role' => 'control',
            ],
        );
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:DELETE /nodes/{name}';
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
            'target_node' => $this->activityTargetName ?? (string) request()->route('name'),
            'removed_self' => $this->activityRemovedSelf,
            'grants_removed' => $this->activityGrantsRemoved,
            'wireguard_peer_removed' => $this->activityWireGuardPeerRemoved,
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('name');

        return $target !== '' ? "Node {$target} removed" : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
