<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Activity\ActivityHistory;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[RequiresPermission('activity:read', servingNode: ServingNode::Gateway)]
final class ActivityListController implements Loggable
{
    private const array VALID_EFFECTS = ['read', 'write', 'destructive'];

    /**
     * @var array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool, limit: int}
     */
    private array $filters = [
        'app' => null,
        'node' => null,
        'effect' => null,
        'correlation' => null,
        'include_internal' => false,
        'limit' => 25,
    ];

    private int $resultCount = 0;

    #[OpenApiResponse(
        status: 200,
        description: 'The canonical activity inventory.',
        type: 'array{success: array{data: array{activities: list<array{id: int, occurred_at: string, correlation_id: string|null, type: string, effect: string, subject: array{type: string, name: string}|null, actor: array{node: string}|null, command: string|null, description: string|null, properties: array<string, mixed>, channel: string}>}, meta: array{filters: array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool}, limit: int, count: int, has_more: bool}}}',
    )]
    public function __invoke(Request $request, ActivityHistory $history): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $this->filters = $filters;

        $result = $history->list($filters);
        $this->resultCount = (int) $result['meta']['count'];

        return response()->json([
            'success' => [
                'data' => [
                    'activities' => $result['activities'],
                ],
                'meta' => $result['meta'],
            ],
        ]);
    }

    /**
     * @return array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool, limit: int}|JsonResponse
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

        $includeInternal = $request->query('include_internal');
        if (
            $includeInternal !== null
            && filter_var($includeInternal, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null
        ) {
            return $this->validationFailed('include_internal', 'invalid');
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
            'include_internal' => filter_var($includeInternal, FILTER_VALIDATE_BOOL),
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
            'filter_include_internal' => $this->filters['include_internal'],
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
