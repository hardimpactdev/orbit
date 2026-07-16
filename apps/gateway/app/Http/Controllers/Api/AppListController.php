<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppResponsePayload;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
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
    ) {}

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
        $payloads = $this->listPayloads($apps, $callerIsGateway, $visibleNodeIds);

        return response()->json([
            'success' => [
                'data' => [
                    'apps' => $payloads['apps'],
                    'inventory' => $payloads['inventory'],
                ],
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
        $query->with(['node', 'instances', 'workspaces.appInstance', 'dependencyAuditSummaries']);

        if (! $callerIsGateway) {
            $query->whereHas('instances', static function (Builder $query) use ($visibleNodeIds): void {
                $query->where('driver', AppInstanceDriver::Orbit->value)->whereIn(
                    'driver_config->data->node_id',
                    $visibleNodeIds,
                );
            });
        }

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        $query->getQuery()->orderByRaw('LOWER(name)');

        return $query->get();
    }

    /**
     * @param  Collection<int, App>  $apps
     * @param  list<int>  $visibleNodeIds
     * @return array{
     *     apps: list<array<string, mixed>>,
     *     inventory: list<array{
     *         app: string,
     *         instance_count: int,
     *         workspace_count: int,
     *     }>,
     * }
     */
    private function listPayloads(Collection $apps, bool $callerIsGateway, array $visibleNodeIds): array
    {
        $appPayload = app(AppResponsePayload::class);
        $appPayloads = [];
        $inventoryPayloads = [];

        foreach ($apps as $app) {
            $workspaces = $this->workspacePayloads($app, $callerIsGateway, $visibleNodeIds);

            $appPayloads[] = [
                ...$appPayload->forApp($app),
                'workspaces' => $workspaces,
            ];
            $inventoryPayloads[] = [
                'app' => $app->name,
                'instance_count' => $this->visibleInstanceCount($app, $callerIsGateway, $visibleNodeIds),
                'workspace_count' => count($workspaces),
            ];
        }

        return [
            'apps' => $appPayloads,
            'inventory' => $inventoryPayloads,
        ];
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
            ->filter(fn (AppInstance $instance): bool => in_array(
                $this->instanceNodeId($instance),
                $visibleNodeIds,
                strict: true,
            ))
            ->count();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return list<array<string, mixed>>
     */
    private function workspacePayloads(App $app, bool $callerIsGateway, array $visibleNodeIds): array
    {
        $payloads = [];

        foreach ($app->workspaces->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $workspace) {
            if (
                ! $workspace instanceof Workspace
                || ! $callerIsGateway
                && ! in_array($this->instanceNodeId($workspace->appInstance), $visibleNodeIds, strict: true)
            ) {
                continue;
            }

            $payloads[] = [
                'name' => $workspace->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ];
        }

        return $payloads;
    }

    private function instanceNodeId(?AppInstance $instance): ?int
    {
        $config = $instance?->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
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
