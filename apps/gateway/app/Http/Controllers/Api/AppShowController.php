<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\AppShowPlacementPayload;
use App\Services\Apps\AppShowVisibility;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @mago-expect lint:cyclomatic-complexity */
final class AppShowController implements Loggable
{
    private ?App $activitySubject = null;

    public function __construct(
        private readonly AppShowVisibility $visibility,
        private readonly AppShowPlacementPayload $placementPayload,
    ) {}

    public function __invoke(Request $request, string $app): JsonResponse
    {
        $model = $this->resolveApp($app);

        if (! $model instanceof App) {
            return response()->json([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App '{$app}' not found.",
                    'meta' => [
                        'app' => $app,
                    ],
                ],
            ], 404);
        }

        $this->activitySubject = $model;

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $callerIsGateway = $this->visibility->callerIsGateway($caller);
        $instances = $this->visibility->visibleInstances($model, $caller);

        if (! $callerIsGateway && $instances === []) {
            $servingNode = $this->visibility->firstServingNodeName($model);

            return $this->authorizationFailed(
                $servingNode === null
                    ? 'This node is not authorized to read this app.'
                    : "This node is not authorized for 'app:read' on '{$servingNode}'.",
                array_filter(
                    [
                        'reason' => 'missing_permission',
                        'missing_permission' => 'app:read',
                        'serving_node' => $servingNode,
                    ],
                    static fn (mixed $value): bool => $value !== null,
                ),
            );
        }

        return response()->json([
            'success' => [
                'data' => [
                    'app' => $this->appPayload($model),
                    'details' => $this->detailsPayload($model, $instances),
                ],
            ],
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        $baseQuery = App::query()->with('node');

        $nameMatch = (clone $baseQuery)->where('name', $selector)->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery->where('domain', $selector)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return app(AppResponsePayload::class)->forApp($app);
    }

    /**
     * @param  list<AppInstance>  $instances
     * @return array<string, mixed>
     */
    private function detailsPayload(App $app, array $instances): array
    {
        $app->loadMissing([
            'dependencyAuditSummaries',
            'instances',
            'processes.appInstance',
            'workspaces.appInstance',
        ]);

        $visibleInstanceIds = array_map(static fn (AppInstance $instance): int => $instance->id, $instances);
        $placements = $this->placementPayload->forApp($app, $instances);
        $processModels = [];

        foreach ($app->processes as $process) {
            if (! in_array($process->app_instance_id, $visibleInstanceIds, strict: true)) {
                continue;
            }

            $processModels[] = $process;
        }

        usort(
            $processModels,
            static fn (Process $left, Process $right): int => (
                [
                    $left->app_instance_id,
                    $left->sort_order,
                    $left->id,
                ] <=> [
                    $right->app_instance_id,
                    $right->sort_order,
                    $right->id,
                ]
            ),
        );

        $processes = [];

        foreach ($processModels as $process) {
            $processes[] = [
                'name' => $process->name,
                'app_instance' => $process->appInstance?->name,
                'runtime' => $process->runtime->value,
            ];
        }

        return [
            'domain' => $this->domain($app),
            'document_root' => $app->documentRootPath(),
            'node' => [
                'name' => $app->node?->name,
                'host' => $app->node?->host,
            ],
            'agent_ide' => $this->agentIdePayload($app),
            'dependency_audits' => app(AppDependencyAuditAggregatePayload::class)->managerDetailsFor($app),
            'instances' => $placements['instances'],
            'workspaces' => $placements['workspaces'],
            'processes' => $processes,
            'routes' => [
                [
                    'host' => $this->domain($app),
                    'kind' => 'app',
                    'owner' => 'app',
                ],
            ],
        ];
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

    private function domain(App $app): ?string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $host = parse_url($app->url(), PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    /**
     * @return array{adapter: string|null, inherited_from: string, workspace_discovery: string|null}
     */
    private function agentIdePayload(App $app): array
    {
        $agentIde = app(AppAgentIdeDefaults::class)->payloadFor($app);
        $effectiveAdapter = $agentIde['effective_adapter'];

        return [
            'adapter' => $effectiveAdapter,
            'inherited_from' => $agentIde['source'],
            'workspace_discovery' => $effectiveAdapter === null ? null : $this->workspaceDiscovery($effectiveAdapter),
        ];
    }

    private function workspaceDiscovery(string $adapter): string
    {
        return in_array($adapter, ['opencode', 'polyscope'], true) ? 'available' : 'unsupported';
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
        return 'api:GET /apps/{app}';
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
