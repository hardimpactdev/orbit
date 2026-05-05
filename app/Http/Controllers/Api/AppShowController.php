<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AppShowController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(Request $request, string $app): JsonResponse
    {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $model = $this->resolveVisibleApp($app, $caller);

        if (! $model instanceof App) {
            return response()->json([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App '{$app}' not found or not visible.",
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

    private function resolveVisibleApp(string $selector, Node $caller): ?App
    {
        $baseQuery = App::query()
            ->with('node')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('node_id', $this->visibleAppNodeIds($caller)));

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
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller): array
    {
        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->where('nodes.role', 'app')
            ->where('nodes.status', 'active')
            ->pluck('nodes.id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsPayload(App $app): array
    {
        return [
            'domain' => $this->domain($app),
            'document_root' => $app->documentRootPath(),
            'node' => [
                'name' => $app->node?->name,
                'host' => $app->node?->host,
            ],
            'agent_ide' => [
                'adapter' => null,
                'inherited_from' => 'default',
                'workspace_discovery' => null,
            ],
            'workspaces' => [],
            'processes' => [],
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
