<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class AppListController implements Loggable
{
    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $node = $request->query('node');
        $environment = $request->query('environment');

        if (is_string($environment) && $environment !== '' && ! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for environment: '{$environment}'. Allowed values: ".implode(', ', self::VALID_ENVIRONMENTS).'.',
                    'meta' => [
                        'field' => 'environment',
                        'value' => $environment,
                        'allowed' => self::VALID_ENVIRONMENTS,
                    ],
                ],
            ], 400);
        }

        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if ($caller->role !== 'gateway' && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the app registry.');
        }

        if (is_string($node) && $node !== '' && ! $this->nodeFilterIsValid($node, $caller, $visibleNodeIds)) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for --node: '{$node}'. Expected a visible app node name.",
                    'meta' => [
                        'field' => 'node',
                        'value' => $node,
                    ],
                ],
            ], 400);
        }

        $apps = $this->fetchApps(
            caller: $caller,
            visibleNodeIds: $visibleNodeIds,
            node: is_string($node) && $node !== '' ? $node : null,
            environment: is_string($environment) && $environment !== '' ? $environment : null,
        );

        return response()->json([
            'success' => [
                'data' => [
                    'apps' => $this->appPayloads($apps),
                ],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller): array
    {
        if ($caller->role === 'gateway') {
            return Node::query()
                ->where('role', 'app')
                ->pluck('id')
                ->all();
        }

        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->where('nodes.role', 'app')
            ->where('nodes.status', 'active')
            ->pluck('nodes.id')
            ->all();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function nodeFilterIsValid(string $node, Node $caller, array $visibleNodeIds): bool
    {
        return Node::query()
            ->where('name', $node)
            ->where('role', 'app')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds))
            ->exists();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, App>
     */
    private function fetchApps(Node $caller, array $visibleNodeIds, ?string $node, ?string $environment): Collection
    {
        return App::query()
            ->with('node')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->when($environment !== null, fn (Builder $query): Builder => $query->where('environment', $environment))
            ->get()
            ->sort(fn (App $first, App $second): int => [
                mb_strtolower((string) $first->node?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->node?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, App>  $apps
     * @return list<array<string, mixed>>
     */
    private function appPayloads(Collection $apps): array
    {
        return $apps->map(fn (App $app): array => [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ])->all();
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    public function activityLogType(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogAction(): string
    {
        return 'api:GET /apps';
    }

    public function activityLogSubject(): ?Model
    {
        return null;
    }

    public function activityLogProperties(): array
    {
        return [];
    }

    public function activityLogDescription(): ?string
    {
        return null;
    }
}
