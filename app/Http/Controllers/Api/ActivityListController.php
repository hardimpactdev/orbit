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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class ActivityListController implements Loggable
{
    private const array VALID_EFFECTS = ['read', 'write', 'destructive'];

    /**
     * @var array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}
     */
    private array $filters = [
        'app' => null,
        'node' => null,
        'effect' => null,
        'correlation' => null,
        'limit' => 25,
    ];

    private int $resultCount = 0;

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $this->filters = $filters;

        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->when($filters['effect'] !== null, fn (Builder $query): Builder => $query->where('properties->type', $filters['effect']))
            ->when($filters['correlation'] !== null, fn (Builder $query): Builder => $query->where('batch_uuid', $filters['correlation']))
            ->when($filters['node'] !== null, fn (Builder $query): Builder => $this->applyNodeFilter($query, $filters['node']))
            ->when($filters['app'] !== null, fn (Builder $query): Builder => $this->applyAppFilter($query, $filters['app']))
            ->orderByDesc('id');

        $rows = $query
            ->limit($filters['limit'] + 1)
            ->get();

        $hasMore = $rows->count() > $filters['limit'];
        $activities = $rows
            ->take($filters['limit'])
            ->values();

        $this->resultCount = $activities->count();

        return response()->json([
            'success' => [
                'data' => [
                    'activities' => $this->activityPayloads($activities),
                ],
                'meta' => [
                    'filters' => [
                        'app' => $filters['app'],
                        'node' => $filters['node'],
                        'effect' => $filters['effect'],
                        'correlation' => $filters['correlation'],
                    ],
                    'limit' => $filters['limit'],
                    'count' => $this->resultCount,
                    'has_more' => $hasMore,
                ],
            ],
        ]);
    }

    /**
     * @return array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}|JsonResponse
     */
    private function validatedFilters(Request $request): array|JsonResponse
    {
        $app = $request->query('app');
        if ($app !== null && (! is_string($app) || $app === '')) {
            return $this->validationFailed('app', 'invalid');
        }

        $node = $request->query('node');
        if ($node !== null && (! is_string($node) || $node === '')) {
            return $this->validationFailed('node', 'invalid');
        }

        $effect = $request->query('effect');
        if ($effect !== null && (! is_string($effect) || ! in_array($effect, self::VALID_EFFECTS, true))) {
            return $this->validationFailed('effect', 'unsupported_value');
        }

        $correlation = $request->query('correlation');
        if ($correlation !== null && (! is_string($correlation) || ! Str::isUuid($correlation))) {
            return $this->validationFailed('correlation', 'invalid');
        }

        $limit = $request->query('limit', '25');
        if (! is_scalar($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false) {
            return $this->validationFailed('limit', 'invalid');
        }

        $normalizedLimit = (int) $limit;
        if ($normalizedLimit < 1 || $normalizedLimit > 200) {
            return $this->validationFailed('limit', 'out_of_range');
        }

        return [
            'app' => is_string($app) ? $app : null,
            'node' => is_string($node) ? $node : null,
            'effect' => is_string($effect) ? $effect : null,
            'correlation' => is_string($correlation) ? $correlation : null,
            'limit' => $normalizedLimit,
        ];
    }

    private function validationFailed(string $field, string $reason): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Invalid activity filter.',
                'meta' => [
                    'field' => $field,
                    'reason' => $reason,
                ],
            ],
        ], 400);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyNodeFilter(Builder $query, string $node): Builder
    {
        return $query->where(function (Builder $query) use ($node): void {
            $query
                ->whereHasMorph('causer', [Node::class], fn (Builder $query): Builder => $query->where('name', $node))
                ->orWhereHasMorph('subject', [Node::class], fn (Builder $query): Builder => $query->where('name', $node))
                ->orWhere('properties->node', $node)
                ->orWhere('properties->target_node', $node)
                ->orWhere('properties->serving_node', $node);
        });
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyAppFilter(Builder $query, string $app): Builder
    {
        return $query->where(function (Builder $query) use ($app): void {
            $query
                ->whereHasMorph('subject', [App::class], fn (Builder $query): Builder => $query->where('name', $app))
                ->orWhere('properties->app', $app)
                ->orWhere('properties->app_name', $app);
        });
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return list<array<string, mixed>>
     */
    private function activityPayloads(Collection $activities): array
    {
        return $activities->map(fn (Activity $activity): array => [
            'id' => $activity->id,
            'occurred_at' => $activity->created_at?->toIso8601String(),
            'correlation_id' => $activity->batch_uuid,
            'type' => $activity->event,
            'effect' => $activity->properties->get('type'),
            'subject' => $this->subjectPayload($activity->subject),
            'actor' => $this->actorPayload($activity->causer),
            'command' => $activity->properties->get('command'),
            'summary' => $activity->description,
        ])->all();
    }

    /**
     * @return array{type: string, name: string|null}|null
     */
    private function subjectPayload(?Model $subject): ?array
    {
        if ($subject instanceof App) {
            return [
                'type' => 'app',
                'name' => $subject->name,
            ];
        }

        if ($subject instanceof Node) {
            return [
                'type' => 'node',
                'name' => $subject->name,
            ];
        }

        if ($subject !== null) {
            return [
                'type' => Str::of(class_basename($subject))->snake()->toString(),
                'name' => (string) $subject->getKey(),
            ];
        }

        return null;
    }

    /**
     * @return array{node: string|null, role: string|null}|null
     */
    private function actorPayload(?Model $causer): ?array
    {
        if (! $causer instanceof Node) {
            return null;
        }

        return [
            'node' => $causer->name,
            'role' => $causer->role,
        ];
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
        return 'activity.listed';
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

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'filter_app' => $this->filters['app'],
            'filter_node' => $this->filters['node'],
            'filter_effect' => $this->filters['effect'],
            'filter_correlation' => $this->filters['correlation'],
            'filter_limit' => $this->filters['limit'],
            'result_count' => $this->resultCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): string
    {
        return "listed {$this->resultCount} activity entries";
    }

    public function activityLogDescription(): string
    {
        return $this->description();
    }
}
