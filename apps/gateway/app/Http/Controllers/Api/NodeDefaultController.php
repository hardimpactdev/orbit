<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Requests\Api\SetDefaultNodeApiRequest;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class NodeDefaultController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $error = $this->deploymentContextError($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        return $this->success(
            action: 'show',
            defaultNode: $this->defaultNodePayload($this->readDefaultNode()),
        );
    }

    public function set(SetDefaultNodeApiRequest $request): JsonResponse
    {
        $error = $this->deploymentContextError($request);

        if ($error instanceof JsonResponse) {
            return $error;
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

        if (! $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-dev')) {
            return $this->error(
                code: 'node.invalid_role',
                message: "Node '{$name}' is not a development app node.",
                meta: [
                    'name' => $name,
                    'role' => $node->displayRole(),
                    'environment' => $this->nodeRoleAssignments->activeAppHostEnvironment($node),
                    'required_role_assignment' => 'app-dev',
                ],
                status: 422,
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
        $error = $this->deploymentContextError($request);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        $wasSet = $this->readDefaultNode() !== null;

        $this->writeDefaultNode(null);

        return $this->success(
            action: 'clear',
            defaultNode: null,
            meta: ['was_set' => $wasSet],
        );
    }

    private function deploymentContextError(Request $request): ?JsonResponse
    {
        if ((bool) config('orbit.is_gateway', false)) {
            return $this->error(
                code: 'validation_failed',
                message: 'node:default is not supported on gateway nodes.',
                meta: ['reason' => 'not_supported_on_gateway'],
                status: 422,
            );
        }

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        return null;
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

    public function effect(): ActivityLogType
    {
        return request()->isMethod('GET') ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return sprintf('api:%s /nodes/default', request()->method());
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        if (! request()->isMethod('PUT')) {
            return null;
        }

        return Node::query()
            ->where('name', (string) request('name'))
            ->first();
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'action' => $this->activityAction(),
            'default_node' => $this->activityDefaultNode(),
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return match ($this->activityAction()) {
            'set' => sprintf('Default node set to %s', (string) request('name')),
            'clear' => 'Default node cleared',
            default => null,
        };
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    private function activityAction(): string
    {
        return match (request()->method()) {
            'PUT' => 'set',
            'DELETE' => 'clear',
            default => 'show',
        };
    }

    private function activityDefaultNode(): ?string
    {
        if (request()->isMethod('PUT')) {
            $name = request('name');

            return is_string($name) && $name !== '' ? $name : null;
        }

        if (request()->isMethod('DELETE')) {
            return null;
        }

        return $this->readDefaultNode();
    }
}
