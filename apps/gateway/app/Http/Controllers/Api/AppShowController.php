<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Process;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
final class AppShowController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $app): JsonResponse
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

        return response()->json([
            'success' => [
                'data' => [
                    'app' => $this->appPayload($model),
                    'details' => $this->detailsPayload($model),
                ],
            ],
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        $baseQuery = App::query()
            ->with('node');

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery
            ->where('domain', $selector)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return app(AppResponsePayload::class)->forApp($app);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsPayload(App $app): array
    {
        $app->loadMissing(['dependencyAuditSummaries', 'processes.appInstance']);

        $processModels = $app->processes->all();
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
            'workspaces' => [],
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

    private function domain(App $app): ?string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $host = parse_url($app->url(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
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
