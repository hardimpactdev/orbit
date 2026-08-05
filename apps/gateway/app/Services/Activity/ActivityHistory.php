<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class ActivityHistory
{
    /**
     * @var list<string>
     */
    private const array INTERNAL_LOG_NAMES = [
        'local_executor',
        'remote_shell',
    ];

    /**
     * @var list<string>
     */
    private const array INTERNAL_LANES = [
        'internal',
        'local-executor',
    ];

    /**
     * @param  array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool, limit: int}  $filters
     * @return array{
     *     activities: list<array<string, mixed>>,
     *     meta: array{
     *         filters: array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool},
     *         limit: int,
     *         count: int,
     *         has_more: bool
     *     }
     * }
     */
    public function list(array $filters): array
    {
        /** @var Builder<Activity> $query */
        $query = Activity::query()->with(['causer', 'subject']);

        if (! $filters['include_internal']) {
            $query = $this->applyInternalVisibilityFilter($query);
        }

        if ($filters['effect'] !== null) {
            if ($filters['effect'] === 'write') {
                $query->where(function (Builder $query): void {
                    $query
                        ->where('properties->type', 'write')
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->where('log_name', 'security')
                                ->where('event', 'like', 'node.host_key.%');
                        });
                });
            } else {
                $query->where('properties->type', $filters['effect']);
            }
        }

        if ($filters['correlation'] !== null) {
            $query->where('batch_uuid', $filters['correlation']);
        }

        if ($filters['node'] !== null) {
            $query = $this->applyNodeFilter($query, $filters['node']);
        }

        if ($filters['app'] !== null) {
            $query = $this->applyAppFilter($query, $filters['app']);
        }

        $query->orderByDesc('id');

        $rows = $query
            ->limit($filters['limit'] + 1)
            ->get();

        $hasMore = $rows->count() > $filters['limit'];
        $activities = $rows
            ->take($filters['limit'])
            ->values();

        return [
            'activities' => $this->activityPayloads($activities),
            'meta' => [
                'filters' => [
                    'app' => $filters['app'],
                    'node' => $filters['node'],
                    'effect' => $filters['effect'],
                    'correlation' => $filters['correlation'],
                    'include_internal' => $filters['include_internal'],
                ],
                'limit' => $filters['limit'],
                'count' => $activities->count(),
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @return array{
     *     activity: array<string, mixed>,
     *     related: list<array<string, mixed>>,
     *     meta: array{related_count: int}
     * }|null
     */
    public function show(int $id): ?array
    {
        $activity = Activity::query()
            ->with(['causer', 'subject'])
            ->find($id);

        if (! $activity instanceof Activity) {
            return null;
        }

        $related = $this->relatedActivities($activity);

        return [
            'activity' => $this->activityPayload($activity),
            'related' => $related,
            'meta' => [
                'related_count' => count($related),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relatedActivities(Activity $activity): array
    {
        if (! is_string($activity->batch_uuid) || $activity->batch_uuid === '') {
            return [];
        }

        /** @var Builder<Activity> $query */
        $query = Activity::query()->with(['causer', 'subject']);
        $query
            ->where('batch_uuid', $activity->batch_uuid)
            ->whereKeyNot($activity->id)
            ->orderBy('created_at')
            ->orderBy('id');

        return array_values(
            $query
                ->get()
                ->map(fn (Activity $related): array => $this->activityPayload($related))
                ->all(),
        );
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyInternalVisibilityFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('log_name')
                        ->orWhereNotIn('log_name', self::INTERNAL_LOG_NAMES);
                })
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('properties->lane')
                        ->orWhereNotIn('properties->lane', self::INTERNAL_LANES);
                });
        });
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
                ->orWhereHasMorph('subject', [Node::class], fn (Builder $query): Builder => $query->where(
                    'name',
                    $node,
                ))
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
                ->whereHasMorph('subject', [App::class], fn (Builder $query): Builder => $query->where(
                    'name',
                    $app,
                ))
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
        return $activities->map(fn (Activity $activity): array => $this->activityPayload($activity))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPayload(Activity $activity): array
    {
        /** @var array<string, mixed> $properties */
        $properties = $activity
            ->properties
            ->except(['type', 'command'])
            ->toArray();
        $formatted = ActivityPayloadFormatter::format($activity, $properties);

        return [
            'id' => $activity->id,
            'occurred_at' => $activity->created_at?->toIso8601String(),
            'correlation_id' => $activity->batch_uuid,
            'type' => $activity->event,
            'effect' => $formatted['effect'],
            'subject' => $this->subjectPayload($activity->subject),
            'actor' => $this->actorPayload($activity->causer),
            'command' => $activity->properties->get('command'),
            'description' => $activity->description,
            'properties' => $formatted['properties'] === [] ? (object) [] : $formatted['properties'],
            'channel' => $formatted['channel'],
        ];
    }

    /**
     * @return array{type: string, name: string}|null
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
     * @return array{node: string}|null
     */
    private function actorPayload(?Model $causer): ?array
    {
        if (! $causer instanceof Node) {
            return null;
        }

        return [
            'node' => $causer->name,
        ];
    }
}
