<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ApplicationLogs\ShowApplicationLog;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Instance;
use App\Models\Node;
use App\Services\ApplicationLogs\ApplicationLogActivityProperties;
use App\Services\ApplicationLogs\ApplicationLogLines;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\GatewayApiException;

#[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
final class InstanceApplicationLogController implements Loggable
{
    private ?Model $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly AppSelectorResolver $selectors,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function __invoke(
        string $instance,
        Request $request,
        ShowApplicationLog $showApplicationLog,
    ): JsonResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $lines = ApplicationLogLines::fromRequest($request);
            $selection = $this->selectors->requireInstance(
                $this->selectors->resolveRequired($instance),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->selectionFailed($exception, $instance);
        } catch (GatewayApiException $exception) {
            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                422,
            );
        }

        $targetInstance = $selection->instance;

        if (! $targetInstance instanceof Instance) {
            return $this->error(
                'instance.not_found',
                "Instance '{$instance}' not found.",
                ['instance' => $instance],
                404,
            );
        }

        $serving = $this->placement->nodeForInstance($targetInstance);

        if (! $serving instanceof Node) {
            return $this->error(
                'validation_failed',
                'The instance serving node could not be resolved.',
                [
                    'field' => 'instance',
                    'instance' => $instance,
                ],
                422,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $serving, 'instance:read');

        if (! $authorization->allowed) {
            $this->recordActivity(
                request: $request,
                selector: "{$selection->app->name}.{$targetInstance->name}",
                target: null,
                mode: 'bounded',
                lines: $lines,
                outcome: 'unauthorized',
            );

            return $this->error(
                'authorization_failed',
                "This node is not authorized for 'instance:read' on '{$serving->name}'.",
                [
                    'reason' => $authorization->reason,
                    'missing_permission' => $authorization->missingPermission ?? 'instance:read',
                    'serving_node' => $serving->name,
                ],
                403,
            );
        }

        try {
            $result = $showApplicationLog->forInstance(
                app: $selection->app,
                instance: $targetInstance,
                lines: $lines,
                nodeConstraint: $this->optionalString($request, 'node'),
            );
        } catch (GatewayApiException $exception) {
            $this->recordActivity(
                request: $request,
                selector: "{$selection->app->name}.{$targetInstance->name}",
                target: null,
                mode: 'bounded',
                lines: $lines,
                outcome: $exception->errorCode() ?? 'validation_failed',
            );

            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                match ($exception->errorCode()) {
                    'instance.not_found' => 404,
                    'authorization_failed' => 403,
                    'application_log.read_failed' => 502,
                    default => 422,
                },
            );
        }

        $this->activitySubject = $targetInstance;
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $target = is_array($data['target'] ?? null) ? $data['target'] : null;
        $this->activityProperties = ApplicationLogActivityProperties::forInstance(
            request: $request,
            selector: "{$selection->app->name}.{$targetInstance->name}",
            target: $target,
            mode: 'bounded',
            lines: $lines,
            outcome: 'success',
        );

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $target
     */
    private function recordActivity(
        Request $request,
        string $selector,
        ?array $target,
        string $mode,
        int $lines,
        string $outcome,
    ): void {
        $this->activityProperties = ApplicationLogActivityProperties::forInstance(
            request: $request,
            selector: $selector,
            target: $target,
            mode: $mode,
            lines: $lines,
            outcome: $outcome,
        );
    }

    private function selectionFailed(AppSelectionResolutionFailed $exception, string $instance): JsonResponse
    {
        $required =
            $exception->errorCode === 'validation_failed'
            && ($exception->meta['reason'] ?? null) === 'instance_required';

        return $this->error(
            $required ? $exception->errorCode : 'instance.not_found',
            $required ? $exception->getMessage() : "Instance '{$instance}' not found.",
            $required ? $exception->meta : ['instance' => $instance],
            $required ? 422 : 404,
        );
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json(JsonEnvelope::failure($code, $message, $meta), $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /instances/{instance}/log';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return $this->activityProperties;
    }

    public function description(): ?string
    {
        return null;
    }
}
