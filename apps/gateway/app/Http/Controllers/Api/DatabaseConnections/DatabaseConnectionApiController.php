<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class DatabaseConnectionApiController implements Loggable
{
    private const string ActivityPropertiesAttribute = 'database_connection_activity_properties';

    private const string ActivitySubjectAttribute = 'database_connection_activity_subject';

    public function __construct(
        protected readonly DatabaseConnectionRegistry $registry,
        protected readonly DatabaseConnectionPayloadMapper $payloads,
        protected readonly DatabaseConnectionTargetResolver $resolver,
        protected readonly NodeRoleAssignments $roles,
        protected readonly NodeAccessAuthorizer $authorizer,
        protected readonly DatabaseConnectionSelector $selector,
        protected readonly DatabaseConnectionExecutor $executor,
        protected readonly DatabaseAuditPayload $audit,
        protected readonly WorkspacePlacement $workspacePlacement,
    ) {}

    abstract public function effect(): ActivityLogType;

    abstract public function type(): string;

    public function subject(): ?Model
    {
        $subject = request()->attributes->get(self::ActivitySubjectAttribute);

        return $subject instanceof DatabaseConnection ? $subject : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $properties = request()->attributes->get(self::ActivityPropertiesAttribute);

        return is_array($properties) ? $properties : [];
    }

    public function description(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function setActivityProperties(Request $request, array $properties): void
    {
        $request->attributes->set(self::ActivityPropertiesAttribute, $properties);
    }

    protected function setActivitySubject(Request $request, DatabaseConnection $connection): void
    {
        $request->attributes->set(self::ActivitySubjectAttribute, $connection);
    }

    protected function authorizeCaller(Request $request): JsonResponse|Node
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node || ! $caller->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => (object) [],
                ],
            ], 403);
        }

        return $caller;
    }

    protected function authorizeListScope(
        Node $caller,
        ?Instance $instance,
        ?Workspace $workspace,
        ?Node $node,
    ): ?JsonResponse {
        if ($instance instanceof Instance) {
            return $this->authorizeNodePermission($caller, $this->ownerNode($instance), 'database:read');
        }

        if ($workspace instanceof Workspace) {
            return $this->authorizeNodePermission($caller, $this->ownerNode($workspace), 'database:read');
        }

        if ($node instanceof Node) {
            return $this->authorizeNodePermission($caller, $node, 'database:read');
        }

        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        foreach (Node::query()->get() as $servingNode) {
            if ($this->authorizer->allows($caller, $servingNode, 'database:read')) {
                return null;
            }
        }

        return $this->authorizationFailed($caller, 'database:read');
    }

    protected function authorizeNodePermission(Node $caller, ?Node $servingNode, string $permission): ?JsonResponse
    {
        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        if ($servingNode instanceof Node) {
            $result = $this->authorizer->authorize($caller, $servingNode, $permission);

            if ($result->allowed) {
                return null;
            }

            return $this->authorizationFailed($caller, $result->missingPermission ?? $permission, $servingNode);
        }

        return $this->authorizationFailed($caller, $permission, $servingNode);
    }

    protected function authorizeConnectionPermission(
        Node $caller,
        DatabaseConnection $connection,
        string $permission,
        bool $requireAll = false,
    ): ?JsonResponse {
        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        $nodes = $this->connectionServingNodes($connection, $caller);

        if ($nodes === []) {
            return $this->authorizeNodePermission($caller, $this->gatewayNode(), $permission);
        }

        if ($requireAll) {
            foreach ($nodes as $node) {
                $authorization = $this->authorizeNodePermission($caller, $node, $permission);

                if ($authorization instanceof JsonResponse) {
                    return $authorization;
                }
            }

            return null;
        }

        foreach ($nodes as $node) {
            if ($this->authorizer->allows($caller, $node, $permission)) {
                return null;
            }
        }

        return $this->authorizationFailed($caller, $permission, servingNodes: array_map(
            static fn (Node $node): string => $node->name,
            $nodes,
        ));
    }

    protected function connectionAllowsAny(Node $caller, DatabaseConnection $connection, string $permission): bool
    {
        if ($this->roles->nodeIsGateway($caller)) {
            return true;
        }

        $nodes = $this->connectionServingNodes($connection, $caller);

        if ($nodes === []) {
            $gateway = $this->gatewayNode();

            return $gateway instanceof Node && $this->authorizer->allows($caller, $gateway, $permission);
        }

        return array_any($nodes, fn ($node) => $this->authorizer->allows($caller, $node, $permission));
    }

    /**
     * @return list<Node>
     */
    protected function connectionServingNodes(DatabaseConnection $connection, ?Node $caller = null): array
    {
        $connection->loadMissing([
            'node',
            'targets.instance.app',
            'targets.workspace.app',
        ]);

        $nodes = [];

        if ($connection->node instanceof Node) {
            $nodes[$connection->node->id] = $connection->node;
        }

        foreach ($connection->targets as $target) {
            $instanceNode = $target->instance instanceof Instance
                ? $this->workspacePlacement->nodeForInstance($target->instance)
                : null;

            if ($instanceNode instanceof Node) {
                $nodes[$instanceNode->id] = $instanceNode;
            }

            $workspaceNode = null;

            if (
                $target->workspace instanceof Workspace
                && (! $caller instanceof Node
                || $this->resolver->workspaceIsSupportedForCaller($target->workspace, $caller))
            ) {
                $workspaceNode = $this->workspacePlacement->nodeForWorkspace($target->workspace);
            }

            if ($workspaceNode instanceof Node) {
                $nodes[$workspaceNode->id] = $workspaceNode;
            }
        }

        return array_values($nodes);
    }

    protected function targetOwnerNode(string $target): ?Node
    {
        $instance = $this->resolver->resolveInstanceSelector($target);

        if ($instance instanceof Instance) {
            return $this->ownerNode($instance);
        }

        $workspace = $this->resolver->resolveWorkspace($target);

        if ($workspace instanceof Workspace) {
            return $this->ownerNode($workspace);
        }

        return null;
    }

    protected function ownerNode(App|Workspace|Instance $owner): ?Node
    {
        if ($owner instanceof Instance) {
            return $this->workspacePlacement->nodeForInstance($owner);
        }

        if ($owner instanceof App) {
            return $this->workspacePlacement->runtimeNode($owner, null);
        }

        return $this->workspacePlacement->nodeForWorkspace($owner);
    }

    protected function gatewayNode(): ?Node
    {
        $gateway = $this->roles->activeGatewayNodeQuery()->first();

        return $gateway instanceof Node ? $gateway : null;
    }

    /**
     * @param  list<string>  $servingNodes
     */
    protected function authorizationFailed(
        Node $caller,
        string $permission,
        ?Node $servingNode = null,
        array $servingNodes = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This node is not authorized to manage database connections.',
                'meta' => array_filter(
                    [
                        'reason' => 'missing_permission',
                        'missing_permission' => $permission,
                        'serving_node' => $servingNode?->name,
                        'serving_nodes' => $servingNodes === [] ? null : $servingNodes,
                    ],
                    static fn (mixed $value): bool => $value !== null,
                ),
            ],
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionPayload(Request $request, bool $allowPartial = false): array
    {
        $payload = [];
        $nodeSelector = $this->stringValue($request->input('node'));

        foreach (['slug', 'driver', 'host', 'database', 'path', 'username', 'password'] as $field) {
            $value = $this->stringValue($request->input($field));

            if ($value !== null || ! $allowPartial && $request->has($field)) {
                $payload[$field] = $value;
            }
        }

        if ($request->has('port')) {
            $payload['port'] = $request->input('port');
        }

        if ($request->boolean('clear_password')) {
            $payload['clear_password'] = true;
        }

        if ($request->has('node')) {
            $node = $this->resolver->resolveNode($nodeSelector);

            if ($nodeSelector !== null && $node === null) {
                $payload['__invalid_node'] = $nodeSelector;
            } else {
                $payload['node_id'] = $node?->id;
            }
        }

        $this->setActivityProperties($request, array_filter(
            [
                'slug' => $payload['slug'] ?? $this->stringValue($request->route('connection')),
                'driver' => $payload['driver'] ?? null,
                'node' => $this->stringValue($request->input('node')),
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        return $payload;
    }

    /**
     * @return array{0: 'instance', 1: Instance}|array{0: 'workspace', 1: Workspace}|JsonResponse
     */
    protected function resolveTargetScope(Request $request, string $envPrefix, Node $caller): array|JsonResponse
    {
        $instanceSelector = $this->stringValue($request->input('instance'));
        $workspace = $this->stringValue($request->input('workspace'));
        $workspaceModel = null;

        if ($workspace !== null) {
            try {
                $workspaceModel = $this->resolver->resolveWorkspaceForCaller($workspace, $caller);
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        if (
            $instanceSelector === null
            && $workspace === null
            || $instanceSelector !== null
            && $workspace !== null
        ) {
            return $this->validationFailed(
                'scope',
                'Exactly one of instance or workspace is required.',
                ['field' => 'scope'],
                422,
            );
        }

        if (! $this->resolver->validEnvPrefix($envPrefix)) {
            return $this->validationFailed(
                'env_prefix',
                'Environment prefix must start with a letter and use only uppercase letters, digits, or underscores.',
                [
                    'field' => 'env_prefix',
                    'value' => $envPrefix,
                ],
                422,
            );
        }

        if ($instanceSelector !== null) {
            $instanceModel = $this->resolver->resolveInstanceSelector($instanceSelector);

            if (! $instanceModel instanceof Instance) {
                return $this->validationFailed(
                    'instance',
                    "Invalid value for --instance: '{$instanceSelector}'.",
                    [
                        'field' => 'instance',
                        'value' => $instanceSelector,
                    ],
                    422,
                );
            }

            return ['instance', $instanceModel];
        }

        if ($workspaceModel === null) {
            return $this->validationFailed(
                'workspace',
                "Invalid value for --workspace: '{$workspace}'.",
                ['field' => 'workspace', 'value' => $workspace],
                422,
            );
        }

        return ['workspace', $workspaceModel];
    }

    protected function connectionResponse(
        Request $request,
        DatabaseConnection|DatabaseConnectionRegistryFailure $result,
        int $successStatus,
    ): JsonResponse {
        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->setActivitySubject($request, $result);
        /** @var mixed $caller */
        $caller = $request->user();

        return response()->json([
            'success' => [
                'data' => [
                    'connection' => $this->payloads->toArray(
                        $result,
                        $caller instanceof Node ? $caller : null,
                    ),
                ],
                'meta' => (object) [],
            ],
        ], $successStatus);
    }

    protected function schemaOperation(Request $request, string $operation): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $target = $this->stringValue($request->query('target'));

        if ($target === null) {
            return $this->validationFailed('target', 'Target is required.', ['field' => 'target'], 422);
        }

        $workspaceBoundary = $this->ensureWorkspaceTargetSupported($target, $auth);

        if ($workspaceBoundary instanceof JsonResponse) {
            return $workspaceBoundary;
        }

        $connection = $this->selector->resolve($target, $this->stringValue($request->query('connection')));

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            $this->setActivityProperties($request, [
                'operation' => $operation,
                'target' => $target,
                'selected_connection' => $this->stringValue($request->query('connection')),
                'table' => $this->stringValue($request->query('table')),
                'exit_status' => 'failed',
            ]);

            return $this->failureResponse($connection);
        }

        $requiredPermission = 'database:read';
        $targetNode = $this->targetOwnerNode($target);
        $authorization = $targetNode instanceof Node
            ? $this->authorizeNodePermission($auth, $targetNode, $requiredPermission)
            : $this->authorizeConnectionPermission($auth, $connection, $requiredPermission);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->setActivitySubject($request, $connection);
        $table = $operation === 'describe' ? $this->stringValue($request->query('table')) ?? '' : null;
        $this->setActivityProperties($request, $this->audit->schema($operation, $connection, $target, table: $table));

        try {
            $result = match ($operation) {
                'tables' => $this->executor->tables($connection),
                'schema' => $this->executor->schema($connection),
                'describe' => $this->executor->describe($connection, $table ?? ''),
                default => $this->executor->schema($connection),
            };
            $this->setActivityProperties($request, $this->audit->schema(
                $operation,
                $connection,
                $target,
                $result['meta'],
                $table,
                [
                    'exit_status' => 'success',
                ],
            ));

            return $this->operationResponse($result['data'], $result['meta'], $connection);
        } catch (DatabaseQueryRunnerFailure $failure) {
            $this->setActivityProperties($request, $this->audit->schema(
                $operation,
                $connection,
                $target,
                $failure->meta,
                $table,
                [
                    'exit_status' => 'failed',
                    'error_code' => $failure->errorCode,
                ],
            ));

            return $this->queryFailureResponse($failure);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function operationResponse(array $data, array $meta, DatabaseConnection $connection): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => [
                    'connection' => $connection->slug,
                    'driver' => $connection->driver,
                    ...$meta,
                ],
            ],
        ]);
    }

    protected function queryFailureResponse(DatabaseQueryRunnerFailure $failure): JsonResponse
    {
        return response()->json(
            [
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => $failure->getMessage(),
                    'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
                ],
            ],
            $failure->errorCode === 'database_query.write_not_allowed' ? 422 : 400,
        );
    }

    protected function failureResponse(DatabaseConnectionRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'database_connection.not_found', 'database_connection.target_not_found' => 404,
            'authorization_failed' => 403,
            'validation_failed', 'database_connection.target_conflict', 'database_connection.slug_taken' => 422,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
    }

    protected function ensureWorkspaceTargetSupported(string $target, Node $caller): ?JsonResponse
    {
        if ($this->resolver->resolveInstanceSelector($target) instanceof Instance) {
            return null;
        }

        $workspace = $this->resolver->resolveWorkspace($target);

        if (! $workspace instanceof Workspace) {
            return null;
        }

        try {
            $this->resolver->ensureWorkspaceSupportedForCaller($workspace, $caller);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        return null;
    }

    protected function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function validationFailed(string $field, string $message, array $meta, int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }

    protected function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
