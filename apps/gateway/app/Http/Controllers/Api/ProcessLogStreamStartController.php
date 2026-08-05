<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\ShowProcessLogs;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

/**
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
#[RequiresPermission('process:logs', servingNode: ServingNode::AppOwning)]
final class ProcessLogStreamStartController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly ProcessOwnerContextResolver $contexts,
    ) {}

    public function __invoke(string $name, Request $request, ShowProcessLogs $showProcessLogs): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $context = $this->contexts->resolve(
                nodeName: $this->optionalString($request, 'node'),
                appName: $this->optionalString($request, 'instance'),
                workspaceName: $this->optionalString($request, 'workspace'),
            );
        } catch (GatewayApiException $exception) {
            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                $this->statusFor($exception),
            );
        }

        $authorization = $this->authorizer->authorize($caller, $context->node, 'process:logs');

        if (! $authorization->allowed) {
            return $this->error(
                'authorization_failed',
                "This node is not authorized for 'process:logs' on '{$context->node->name}'.",
                [
                    'reason' => $authorization->reason,
                    'missing_permission' => $authorization->missingPermission,
                    'serving_node' => $context->node->name,
                ],
                403,
            );
        }

        try {
            $target = $showProcessLogs->operationStreamTarget(
                context: $context,
                name: $name,
                lines: $this->lines($request),
                gatewayUrl: $this->gatewayUrl($request),
            );
        } catch (GatewayApiException $exception) {
            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                $this->statusFor($exception),
            );
        }

        $this->activitySubject = $context->subject();

        app()->terminating(static function () use ($showProcessLogs, $target): void {
            try {
                $showProcessLogs->followTarget($target, static function (string $_output): void {});
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });

        $operationRunId = $this->operationRunId($target);

        // Finalize the body and advertise Content-Length so clients can complete the
        // 202 POST before the terminating follow callback ends. Chunked framing would
        // withhold message completion until the long-running tail finishes.
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

    private function lines(Request $request): int
    {
        return $this->integerInput($request->input('lines', 100));
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function operationRunId(array $target): string
    {
        if (! is_array($target['operation_stream'] ?? null)) {
            throw new GatewayApiException(
                'The process log stream operation descriptor is malformed.',
                'process.log_stream_malformed',
            );
        }

        $operationStream = $target['operation_stream'];

        if (is_string($operationStream['operation_uuid'] ?? null) && $operationStream['operation_uuid'] !== '') {
            return $operationStream['operation_uuid'];
        }

        throw new GatewayApiException(
            'The process log stream operation descriptor is malformed.',
            'process.log_stream_malformed',
        );
    }

    private function optionalString(Request $request, string $key): ?string
    {
        return $this->optionalTrimmedString($request->input($key));
    }

    private function optionalTrimmedString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function gatewayUrl(Request $request): string
    {
        $settingsUrl = $this->normalizedUrl(LocalGatewaySettings::current()->gateway_url);

        if ($settingsUrl !== null) {
            return $settingsUrl;
        }

        $requestUrl = $this->normalizedUrl($request->getSchemeAndHttpHost());

        if ($requestUrl !== null) {
            return $requestUrl;
        }

        return $this->normalizedUrl(config('app.url')) ?? '';
    }

    private function normalizedUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : rtrim($value, characters: '/');
    }

    private function integerInput(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'process.not_found' => 404,
            'authorization_failed' => 403,
            'process.log_read_failed' => 502,
            default => 422,
        };
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
        return 'api:POST /processes/{name}/log-stream';
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
        return [
            'node' => $this->optionalString(request(), 'node'),
            'instance' => $this->optionalString(request(), 'instance'),
            'workspace' => $this->optionalString(request(), 'workspace'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
