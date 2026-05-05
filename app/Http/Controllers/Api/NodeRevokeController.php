<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Requests\Api\RevokeNodeApiRequest;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class NodeRevokeController implements Loggable
{
    private ?Node $activitySubject = null;

    private bool $activitySelfLockout = false;

    public function __invoke(RevokeNodeApiRequest $request): JsonResponse
    {
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

        $consumerName = $request->consumingNodeName();
        $servingName = $request->servingNodeName();

        $consumer = $this->resolveNode($consumerName);

        if ($consumer instanceof JsonResponse) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName);

        if ($serving instanceof JsonResponse) {
            return $serving;
        }

        $this->activitySubject = $serving;

        $deleted = NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->delete() > 0;

        $this->activitySelfLockout = $caller->role === 'control'
            && $caller->id === $consumer->id
            && $serving->role === 'gateway';

        return response()->json([
            'success' => [
                'data' => [
                    'consuming_node' => $consumer->name,
                    'serving_node' => $serving->name,
                    'action' => 'revoked',
                    'already_absent' => ! $deleted,
                    'self_lockout' => $this->activitySelfLockout,
                ],
            ],
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
                message: 'This control node is not authorized to revoke grants.',
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
            message: 'This control node is not authorized to revoke grants.',
            meta: [
                'required_node' => $gateway->name,
                'caller_role' => 'control',
            ],
        );
    }

    private function resolveNode(string $name): Node|JsonResponse
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if ($node instanceof Node) {
            return $node;
        }

        return $this->error(
            code: 'node.not_found',
            message: "Node '{$name}' not found.",
            meta: [
                'name' => $name,
            ],
            status: 404,
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
        return 'api:POST /nodes/revoke';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject ?? Node::query()
            ->where('name', (string) request('serving_node'))
            ->first();
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'consuming_node' => (string) request('consuming_node'),
            'serving_node' => (string) request('serving_node'),
            'self_lockout' => $this->activitySelfLockout,
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): string
    {
        return sprintf('%s revoked access to %s', (string) request('consuming_node'), (string) request('serving_node'));
    }

    public function activityLogDescription(): string
    {
        return $this->description();
    }
}
