<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AppListController implements Loggable
{
    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppDependencyAuditAggregatePayload $dependencyAuditPayload,
    ) {}

    #[OpenApiResponse(
        status: 200,
        description: 'The compact app inventory.',
        type: 'array{success: array{data: array{apps: list<array{name: string, repository: string|null, dependency_audit_status: string, dependency_warning_count: int, dependency_danger_count: int, last_dependency_audit_at: string|null, instance_count: int, workspace_count: int}>}, meta: list<mixed>}}',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $environment = $request->query('environment');

        if (
            is_string($environment)
            && $environment !== ''
            && ! in_array($environment, self::VALID_ENVIRONMENTS, true)
        ) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' =>
                        "Invalid value for environment: '{$environment}'. Allowed values: "
                            .implode(', ', self::VALID_ENVIRONMENTS)
                            .'.',
                    'meta' => [
                        'field' => 'environment',
                        'value' => $environment,
                        'allowed' => self::VALID_ENVIRONMENTS,
                    ],
                ],
            ], 400);
        }

        $callerIsGateway = $this->nodeRoleAssignments->nodeIsGateway($caller);
        $visibleNodeIds = $this->visibleAppNodeIds($caller, $callerIsGateway);

        if (! $callerIsGateway && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the app registry.', [
                'reason' => 'missing_permission',
                'missing_permission' => 'app:read',
            ]);
        }

        $apps = $this->fetchApps(
            callerIsGateway: $callerIsGateway,
            visibleNodeIds: $visibleNodeIds,
            environment: is_string($environment) && $environment !== '' ? $environment : null,
        );
        $payloads = $this->listPayloads(
            apps: $apps,
            callerIsGateway: $callerIsGateway,
            callerMayInspectWorkspaces: ! $this->nodeRoleAssignments->nodeHasActiveRole($caller, 'app-prod'),
            visibleNodeIds: $visibleNodeIds,
            workspaceNodeIds: $this->nodeRoleAssignments->activeNodeIdsForRole('app-dev'),
        );

        return response()->json([
            'success' => [
                'data' => [
                    'apps' => $payloads,
                ],
                'meta' => [],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller, bool $callerIsGateway): array
    {
        $visibleNodeIds = $this->hostedAppNodeIds();

        if ($callerIsGateway) {
            return $visibleNodeIds;
        }

        /** @var Builder<Node> $query */
        $query = Node::query();
        $query->whereIn('id', $visibleNodeIds);
        $nodes = $query->get();
        $authorizedNodeIds = [];

        foreach ($nodes as $node) {
            if (! $this->authorizer->allows($caller, $node, 'app:read')) {
                continue;
            }

            $authorizedNodeIds[] = $node->id;
        }

        return $authorizedNodeIds;
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-dev'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-prod'),
        ]));
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, App>
     */
    private function fetchApps(bool $callerIsGateway, array $visibleNodeIds, ?string $environment): Collection
    {
        /** @var Builder<App> $query */
        $query = App::query();
        $query->with(['instances', 'workspaces.instance', 'dependencyAuditSummaries']);

        if (! $callerIsGateway) {
            $query->whereHas('instances', static function (Builder $query) use ($visibleNodeIds): void {
                $query->where('driver', InstanceDriver::Orbit->value)->whereIn(
                    'driver_config->data->node_id',
                    $visibleNodeIds,
                );
            });
        }

        $query->getQuery()->orderByRaw('LOWER(name)');

        $apps = $query->get();

        if ($environment === null) {
            return $apps;
        }

        // App owns no environment: match apps that have any instance resolving
        // to the requested environment.
        $placement = app(WorkspacePlacement::class);

        /** @var Collection<int, App> $filtered */
        $filtered = $apps
            ->filter(static fn (App $app): bool => $app->instances->contains(
                static fn (Instance $instance): bool => (
                    $placement->runtimeEnvironment($app, $instance) === $environment
                ),
            ))
            ->values();

        return $filtered;
    }

    /**
     * @param Collection<int, App> $apps
     * @param  list<int>  $visibleNodeIds
     * @param  list<int>  $workspaceNodeIds
     * @return list<array{
     *     name: string,
     *     repository: string|null,
     *     dependency_audit_status: string,
     *     dependency_warning_count: int,
     *     dependency_danger_count: int,
     *     last_dependency_audit_at: string|null,
     *     instance_count: int,
     *     workspace_count: int,
     * }>
     */
    private function listPayloads(
        Collection $apps,
        bool $callerIsGateway,
        bool $callerMayInspectWorkspaces,
        array $visibleNodeIds,
        array $workspaceNodeIds,
    ): array {
        $payloads = [];

        foreach ($apps as $app) {
            $dependencyAudit = $this->dependencyAuditPayload->forApp($app);

            $payloads[] = [
                'name' => $app->name,
                'repository' => $app->repository,
                'dependency_audit_status' => $dependencyAudit['dependency_audit_status'],
                'dependency_warning_count' => $dependencyAudit['dependency_warning_count'],
                'dependency_danger_count' => $dependencyAudit['dependency_danger_count'],
                'last_dependency_audit_at' => $dependencyAudit['last_dependency_audit_at'],
                'instance_count' => $this->visibleInstanceCount($app, $callerIsGateway, $visibleNodeIds),
                'workspace_count' => $this->visibleWorkspaceCount(
                    app: $app,
                    callerIsGateway: $callerIsGateway,
                    callerMayInspectWorkspaces: $callerMayInspectWorkspaces,
                    visibleNodeIds: $visibleNodeIds,
                    workspaceNodeIds: $workspaceNodeIds,
                ),
            ];
        }

        return $payloads;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function visibleInstanceCount(App $app, bool $callerIsGateway, array $visibleNodeIds): int
    {
        if ($callerIsGateway) {
            return $app->instances->count();
        }

        return $app
            ->instances
            ->filter(fn (Instance $instance): bool => in_array(
                $this->instanceNodeId($instance),
                $visibleNodeIds,
                strict: true,
            ))
            ->count();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @param  list<int>  $workspaceNodeIds
     */
    private function visibleWorkspaceCount(
        App $app,
        bool $callerIsGateway,
        bool $callerMayInspectWorkspaces,
        array $visibleNodeIds,
        array $workspaceNodeIds,
    ): int {
        if (! $callerMayInspectWorkspaces) {
            return 0;
        }

        return $app->workspaces->filter(function (Workspace $workspace) use (
            $callerIsGateway,
            $visibleNodeIds,
            $workspaceNodeIds,
        ): bool {
            $nodeId = $this->instanceNodeId($workspace->instance);

            if (! in_array($nodeId, $workspaceNodeIds, strict: true)) {
                return false;
            }

            return $callerIsGateway || in_array($nodeId, $visibleNodeIds, strict: true);
        })->count();
    }

    private function instanceNodeId(?Instance $instance): ?int
    {
        $config = $instance?->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
            return null;
        }

        return $config->node_id;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 403);
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
        return 'api:GET /apps';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
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
