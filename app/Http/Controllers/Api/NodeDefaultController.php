<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SetDefaultNodeApiRequest;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class NodeDefaultController
{
    public function show(Request $request): JsonResponse
    {
        $caller = $this->controlCaller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        return $this->success(
            action: 'show',
            defaultNode: $this->defaultNodePayload($this->readDefaultNode()),
        );
    }

    public function set(SetDefaultNodeApiRequest $request): JsonResponse
    {
        $caller = $this->controlCaller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $name = $request->defaultNodeName();
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->error(
                code: 'node.not_found',
                message: "Node '{$name}' not found or not visible.",
                meta: ['name' => $name],
                status: 404,
            );
        }

        if ($node->role !== 'app' || $node->environment !== 'development') {
            return $this->error(
                code: 'node.invalid_role',
                message: "Node '{$name}' is not a development app node.",
                meta: [
                    'name' => $name,
                    'role' => $node->role,
                    'environment' => $node->environment,
                    'required_role' => 'app',
                    'required_environment' => 'development',
                ],
                status: 422,
            );
        }

        if (! $this->callerCanAccessNode($caller, $node)) {
            return $this->authorizationFailed(
                message: "This node is not authorized to operate on '{$name}'.",
                meta: [
                    'name' => $name,
                    'caller_role' => $caller->role,
                ],
            );
        }

        $this->writeDefaultNode($name);

        return $this->success(
            action: 'set',
            defaultNode: $this->defaultNodePayload($name),
        );
    }

    public function clear(Request $request): JsonResponse
    {
        $caller = $this->controlCaller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $wasSet = $this->readDefaultNode() !== null;

        $this->writeDefaultNode(null);

        return $this->success(
            action: 'clear',
            defaultNode: null,
            meta: ['was_set' => $wasSet],
        );
    }

    private function controlCaller(Request $request): Node|JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($caller->role !== 'control') {
            return $this->error(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => $caller->role],
                status: 403,
            );
        }

        return $caller;
    }

    private function callerCanAccessNode(Node $caller, Node $node): bool
    {
        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();
    }

    private function readDefaultNode(): ?string
    {
        /** @var string|null $defaultNodeName */
        $defaultNodeName = LocalNodeDefault::query()->value('default_node_name');

        return $defaultNodeName;
    }

    private function writeDefaultNode(?string $name): void
    {
        /** @var LocalNodeDefault $default */
        $default = LocalNodeDefault::query()->firstOrNew();
        $default->default_node_name = $name;
        $default->save();
    }

    /**
     * @return array{name: string, role: string, environment: string}|null
     */
    private function defaultNodePayload(?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'role' => 'app',
            'environment' => 'development',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function success(string $action, ?array $defaultNode, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => [
                'data' => [
                    'action' => $action,
                    'default_node' => $defaultNode,
                ],
            ],
        ];

        if ($meta !== []) {
            $payload['success']['meta'] = $meta;
        }

        return response()->json($payload);
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
}
