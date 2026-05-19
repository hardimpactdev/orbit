<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionController extends Controller implements Loggable
{
    private ?DatabaseConnection $activitySubject = null;

    private string $activityType = 'api:GET /database-connections';

    private ActivityLogType $activityEffect = ActivityLogType::Read;

    /**
     * @var array<string, mixed>
     */
    private array $activityProperties = [];

    public function __construct(
        private readonly DatabaseConnectionRegistry $registry,
        private readonly DatabaseConnectionPayloadMapper $payloads,
        private readonly DatabaseConnectionTargetResolver $resolver,
        private readonly NodeRoleAssignments $roles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->activityType = 'api:GET /database-connections';
        $this->activityEffect = ActivityLogType::Read;

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $app = $this->stringValue($request->query('app'));
        $workspace = $this->stringValue($request->query('workspace'));
        $node = $this->stringValue($request->query('node'));
        $this->activityProperties = array_filter(compact('app', 'workspace', 'node'), static fn (mixed $value): bool => $value !== null);

        if ($app !== null && $workspace !== null) {
            return $this->validationFailed('scope', 'Invalid scope: --app and --workspace cannot be combined.', ['field' => 'scope']);
        }

        $appModel = $app !== null ? $this->resolver->resolveApp($app) : null;
        $workspaceModel = $workspace !== null ? $this->resolver->resolveWorkspace($workspace) : null;
        $nodeModel = $node !== null ? $this->resolver->resolveNode($node) : null;

        if ($app !== null && $appModel === null) {
            return $this->validationFailed('app', "Invalid value for --app: '{$app}'.", ['field' => 'app', 'value' => $app]);
        }

        if ($workspace !== null && $workspaceModel === null) {
            return $this->validationFailed('workspace', "Invalid value for --workspace: '{$workspace}'.", ['field' => 'workspace', 'value' => $workspace]);
        }

        if ($node !== null && $nodeModel === null) {
            return $this->validationFailed('node', "Invalid value for --node: '{$node}'.", ['field' => 'node', 'value' => $node]);
        }

        $connections = $this->registry->list(app: $appModel, workspace: $workspaceModel, node: $nodeModel)
            ->map(fn (DatabaseConnection $connection): array => $this->payloads->toArray($connection))
            ->all();

        return response()->json([
            'success' => [
                'data' => ['connections' => $connections],
                'meta' => ['count' => count($connections)],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->activityType = 'api:POST /database-connections';
        $this->activityEffect = ActivityLogType::Write;

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $payload = $this->connectionPayload($request);
        $slug = $this->stringValue($request->input('slug'));

        if ($slug === null) {
            return $this->validationFailed('slug', 'Database connection slug is required.', ['field' => 'slug']);
        }

        if (isset($payload['__invalid_node'])) {
            return $this->validationFailed('node', "Invalid value for --node: '{$payload['__invalid_node']}'.", [
                'field' => 'node',
                'value' => $payload['__invalid_node'],
            ], 422);
        }

        $result = $this->registry->create($slug, $payload);

        return $this->connectionResponse($result, 200);
    }

    public function show(Request $request, string $connection): JsonResponse
    {
        $this->activityType = 'api:GET /database-connections/{connection}';
        $this->activityEffect = ActivityLogType::Read;
        $this->activityProperties = ['slug' => $connection];

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = $this->registry->show($connection);

        return $this->connectionResponse($result, 200);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $this->activityType = 'api:PATCH /database-connections/{connection}';
        $this->activityEffect = ActivityLogType::Write;

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $payload = $this->connectionPayload($request, allowPartial: true);

        if ($payload === []) {
            return $this->validationFailed('payload', 'At least one mutable field is required.', ['field' => 'payload']);
        }

        if (isset($payload['__invalid_node'])) {
            return $this->validationFailed('node', "Invalid value for --node: '{$payload['__invalid_node']}'.", [
                'field' => 'node',
                'value' => $payload['__invalid_node'],
            ], 422);
        }

        $result = $this->registry->update($connection, $payload);

        return $this->connectionResponse($result, 200);
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        $this->activityType = 'api:DELETE /database-connections/{connection}';
        $this->activityEffect = ActivityLogType::Destructive;
        $this->activityProperties = ['slug' => $connection];

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        if (! $request->boolean('force')) {
            return $this->validationFailed('force', 'Use --force to remove this database connection.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        $result = $this->registry->remove($connection, true);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'result' => [
                        'action' => 'removed',
                        'connection' => $connection,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    public function attach(Request $request, string $connection): JsonResponse
    {
        $this->activityType = 'api:POST /database-connections/{connection}/targets';
        $this->activityEffect = ActivityLogType::Write;

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $envPrefix = $this->stringValue($request->input('env_prefix')) ?? 'DB';
        $scope = $this->resolveTargetScope($request, $envPrefix);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        [$type, $owner] = $scope;
        $this->activityProperties = [
            'slug' => $connection,
            'target_type' => $type,
            'target_name' => $owner->name,
            'env_prefix' => $envPrefix,
        ];

        $result = $type === 'app'
            ? $this->registry->attachToApp($connection, $owner, $envPrefix)
            : $this->registry->attachToWorkspace($connection, $owner, $envPrefix);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        $connectionResult = $this->registry->show($connection);

        return $this->connectionResponse($connectionResult, 200);
    }

    public function detach(Request $request, string $connection): JsonResponse
    {
        $this->activityType = 'api:DELETE /database-connections/{connection}/targets';
        $this->activityEffect = ActivityLogType::Write;

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $envPrefix = $this->stringValue($request->input('env_prefix')) ?? 'DB';
        $scope = $this->resolveTargetScope($request, $envPrefix);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        [$type, $owner] = $scope;
        $this->activityProperties = [
            'slug' => $connection,
            'target_type' => $type,
            'target_name' => $owner->name,
            'env_prefix' => $envPrefix,
        ];

        $result = $type === 'app'
            ? $this->registry->detachFromApp($connection, $owner, $envPrefix)
            : $this->registry->detachFromWorkspace($connection, $owner, $envPrefix);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'result' => [
                        'action' => 'detached',
                        'connection' => $connection,
                        'target_type' => $type,
                        'target' => $owner->name,
                        'env_prefix' => $envPrefix,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return $this->activityEffect;
    }

    public function type(): string
    {
        return $this->activityType;
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return $this->activityProperties;
    }

    public function description(): ?string
    {
        return null;
    }

    private function authorizeCaller(Request $request): JsonResponse|Node
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => (object) [],
                ],
            ], 403);
        }

        if ($caller->status !== 'active') {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to manage database connections.',
                    'meta' => ['caller_role' => $this->roles->assignmentRoleLabel($caller)],
                ],
            ], 403);
        }

        return $caller;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionPayload(Request $request, bool $allowPartial = false): array
    {
        $payload = [];
        $nodeSelector = $this->stringValue($request->input('node'));

        foreach (['slug', 'driver', 'host', 'database', 'path', 'username', 'password'] as $field) {
            $value = $this->stringValue($request->input($field));

            if ($value !== null || (! $allowPartial && $request->has($field))) {
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

        $this->activityProperties = array_filter([
            'slug' => $payload['slug'] ?? $this->stringValue($request->route('connection')),
            'driver' => $payload['driver'] ?? null,
            'node' => $this->stringValue($request->input('node')),
        ], static fn (mixed $value): bool => $value !== null);

        return $payload;
    }

    /**
     * @return array{0: string, 1: App|Workspace}|JsonResponse
     */
    private function resolveTargetScope(Request $request, string $envPrefix): array|JsonResponse
    {
        $app = $this->stringValue($request->input('app'));
        $workspace = $this->stringValue($request->input('workspace'));

        if (($app === null && $workspace === null) || ($app !== null && $workspace !== null)) {
            return $this->validationFailed('scope', 'Exactly one of app or workspace is required.', ['field' => 'scope'], 422);
        }

        if (! $this->resolver->validEnvPrefix($envPrefix)) {
            return $this->validationFailed('env_prefix', 'Environment prefix must start with a letter and use only uppercase letters, digits, or underscores.', [
                'field' => 'env_prefix',
                'value' => $envPrefix,
            ], 422);
        }

        if ($app !== null) {
            $appModel = $this->resolver->resolveApp($app);

            if ($appModel === null) {
                return $this->validationFailed('app', "Invalid value for --app: '{$app}'.", ['field' => 'app', 'value' => $app], 422);
            }

            return ['app', $appModel];
        }

        $workspaceModel = $this->resolver->resolveWorkspace($workspace);

        if ($workspaceModel === null) {
            return $this->validationFailed('workspace', "Invalid value for --workspace: '{$workspace}'.", ['field' => 'workspace', 'value' => $workspace], 422);
        }

        return ['workspace', $workspaceModel];
    }

    private function connectionResponse(DatabaseConnection|DatabaseConnectionRegistryFailure $result, int $successStatus): JsonResponse
    {
        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->activitySubject = $result;

        return response()->json([
            'success' => [
                'data' => [
                    'connection' => $this->payloads->toArray($result),
                ],
                'meta' => (object) [],
            ],
        ], $successStatus);
    }

    private function failureResponse(DatabaseConnectionRegistryFailure $failure): JsonResponse
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $field, string $message, array $meta, int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
