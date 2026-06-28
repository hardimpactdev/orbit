<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Solo\SoloMutationOperationCatalog;
use App\Services\Solo\SoloProxy;
use App\Services\Solo\SoloProxyException;
use App\Services\Solo\SoloReadOperationCatalog;
use App\Services\Solo\SoloUpstreamResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final class SoloProxyController implements Loggable
{
    private string $operation = 'unknown';

    private ?Node $activitySubject = null;

    private ActivityLogType $activityEffect = ActivityLogType::Read;

    #[RequiresPermission('solo:*', servingNode: ServingNode::Gateway)]
    public function tools(Request $request, SoloProxy $proxy): JsonResponse
    {
        $this->operation = 'tools';
        $this->captureActivitySubject($request);

        $node = $this->activitySubject;

        if (! $node instanceof Node) {
            return $this->missingGatewaySubject('solo:*');
        }

        return $this->run(
            response: static fn (): SoloUpstreamResponse => $proxy->tools($node),
            requiredDataKey: 'tools',
        );
    }

    #[RequiresPermission('solo:*', servingNode: ServingNode::Gateway)]
    public function projects(Request $request, SoloProxy $proxy): JsonResponse
    {
        $this->operation = 'projects';
        $this->captureActivitySubject($request);

        $node = $this->activitySubject;

        if (! $node instanceof Node) {
            return $this->missingGatewaySubject('solo:*');
        }

        return $this->run(
            response: static fn (): SoloUpstreamResponse => $proxy->projects($node),
            requiredDataKey: 'projects',
        );
    }

    #[RequiresPermission('solo:*', servingNode: ServingNode::Gateway)]
    public function read(string $operation, Request $request, SoloProxy $proxy): JsonResponse
    {
        try {
            $readOperation = SoloReadOperationCatalog::find($operation);
        } catch (SoloProxyException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $exception->status);
        }

        $this->operation = $readOperation->apiPath;
        $this->captureActivitySubject($request);

        $node = $this->activitySubject;

        if (! $node instanceof Node) {
            return $this->missingGatewaySubject('solo:*');
        }

        return $this->run(
            response: static fn (): SoloUpstreamResponse => $proxy->read($node, $readOperation->upstreamPath($request)),
            requiredDataKey: $readOperation->dataKey,
        );
    }

    public function mutate(
        string $operation,
        Request $request,
        SoloProxy $proxy,
        NodeAccessAuthorizer $authorizer,
    ): JsonResponse {
        try {
            $mutation = SoloMutationOperationCatalog::find($operation);
        } catch (SoloProxyException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $exception->status);
        }

        $this->operation = $mutation->apiPath;
        $this->activityEffect = ActivityLogType::Write;
        $this->captureActivitySubject($request);

        $authorization = $this->authorizeMutation($request, $authorizer, $mutation->permission);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $upstreamPath = $mutation->upstreamPath($request);
            $payload = $mutation->upstreamPayload($request);
        } catch (SoloProxyException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $exception->status);
        }

        $node = $this->activitySubject;

        if (! $node instanceof Node) {
            return $this->missingGatewaySubject($mutation->permission);
        }

        return $this->run(
            response: static fn (): SoloUpstreamResponse => $proxy->mutate($node, $mutation, $upstreamPath, $payload),
            requiredDataKey: $mutation->dataKey,
        );
    }

    /**
     * @param  callable(): SoloUpstreamResponse  $response
     */
    private function run(callable $response, string $requiredDataKey): JsonResponse
    {
        try {
            $result = $response();
        } catch (SoloProxyException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $exception->status);
        }

        if (! $result->ok) {
            $error = $result->error;

            return $this->error(
                code: $error->code ?? 'solo_upstream_error',
                message: $error->message ?? 'Solo API request failed.',
                meta: $error->meta ?? $result->meta,
                status: $error->status ?? 502,
            );
        }

        if (! array_key_exists($requiredDataKey, $result->data) || ! is_array($result->data[$requiredDataKey])) {
            return $this->error(
                code: 'validation_failed',
                message: "Solo upstream response did not include {$requiredDataKey}.",
                meta: [
                    'field' => $requiredDataKey,
                    'reason' => 'solo_upstream_payload_invalid',
                ],
                status: 422,
            );
        }

        return response()->json([
            'success' => [
                'data' => $result->data,
                'meta' => $result->meta,
            ],
        ]);
    }

    private function captureActivitySubject(Request $request): void
    {
        $node = app(ServingNodeResolver::class)->resolve($request, ServingNode::Gateway);

        $this->activitySubject = $node instanceof Node ? $node : null;
    }

    private function missingGatewaySubject(string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            'Serving node could not be resolved for this route.',
            [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => $permission,
            ],
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return $this->activityEffect;
    }

    public function type(): string
    {
        return match ($this->operation) {
            'tools' => 'solo.tools.listed',
            'projects' => 'solo.projects.listed',
            default => 'solo.'
                .str_replace(search: '/', replace: '.', subject: $this->operation)
                .($this->activityEffect === ActivityLogType::Read ? '.read' : ''),
        };
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
            'operation' => $this->operation,
            'target_node' => $this->activitySubject?->name,
        ];
    }

    public function description(): ?string
    {
        return null;
    }

    private function authorizeMutation(
        Request $request,
        NodeAccessAuthorizer $authorizer,
        string $permission,
    ): ?JsonResponse {
        $consumer = $request->user();

        if (! $consumer instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if (! $this->activitySubject instanceof Node) {
            return $this->error(
                'authorization_failed',
                'Serving node could not be resolved for this route.',
                [
                    'reason' => 'serving_node_unresolved',
                    'missing_permission' => $permission,
                ],
                403,
            );
        }

        $result = $authorizer->authorize($consumer, $this->activitySubject, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->error(
            code: 'authorization_failed',
            message: "This node is not authorized for '{$permission}' on '{$this->activitySubject->name}'.",
            meta: [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $this->activitySubject->name,
            ],
            status: 403,
        );
    }
}
