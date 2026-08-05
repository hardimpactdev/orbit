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
use App\Models\LocalGatewaySettings;
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
use Throwable;

#[RequiresPermission('instance:read', servingNode: ServingNode::AppOwning)]
final class InstanceApplicationLogStreamStartController implements Loggable
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
        } catch (AppSelectionResolutionFailed) {
            return $this->error(
                'instance.not_found',
                "Instance '{$instance}' not found.",
                ['instance' => $instance],
                404,
            );
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
                ],
                422,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $serving, 'instance:read');

        if (! $authorization->allowed) {
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

        $selector = "{$selection->app->name}.{$targetInstance->name}";

        try {
            $target = $showApplicationLog->operationStreamTargetForInstance(
                app: $selection->app,
                instance: $targetInstance,
                lines: $lines,
                nodeConstraint: $this->optionalString($request, 'node'),
                gatewayUrl: $this->gatewayUrl($request),
            );
        } catch (GatewayApiException $exception) {
            $this->activityProperties = ApplicationLogActivityProperties::forInstance(
                request: $request,
                selector: $selector,
                target: null,
                mode: 'follow',
                lines: $lines,
                outcome: $exception->errorCode() ?? 'validation_failed',
            );

            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                422,
            );
        }

        $this->activitySubject = $targetInstance;
        $this->activityProperties = ApplicationLogActivityProperties::forInstance(
            request: $request,
            selector: $selector,
            target: [
                'type' => 'instance',
                'app' => $selection->app->name,
                'instance' => $targetInstance->name,
                'workspace' => null,
                'selector' => $selector,
            ],
            mode: 'follow',
            lines: $lines,
            outcome: 'success',
        );

        $operationRunId = (string) ($target['operation_stream']['operation_uuid'] ?? '');

        app()->terminating(static function () use ($showApplicationLog, $target): void {
            try {
                $showApplicationLog->followTarget($target, static function (string $_output): void {});
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });

        $response = response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRunId,
                'stream_descriptor_url' => "/api/operations/{$operationRunId}/stream",
                'events_url' => "/api/operations/{$operationRunId}/events",
            ],
        ]), status: 202);
        $content = (string) $response->getContent();
        $response->headers->set('Content-Length', (string) strlen($content));

        return $response;
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function gatewayUrl(Request $request): string
    {
        $settingsUrl = LocalGatewaySettings::current()->gateway_url;

        if (is_string($settingsUrl) && trim($settingsUrl) !== '') {
            return rtrim(trim($settingsUrl), characters: '/');
        }

        return rtrim($request->getSchemeAndHttpHost(), characters: '/');
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
        return 'api:POST /instances/{instance}/log-stream';
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
