<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Actions\ApplicationLogs\StartApplicationLogStream;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * HTTP resolution for POST /api/instances/{instance}/log-stream.
 *
 * @phpstan-type Result array{response: JsonResponse, subject: ?Model, properties: array<string, mixed>}
 */
final readonly class InstanceApplicationLogStreamHttp
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private InstanceApplicationLogTarget $targets,
        private StartApplicationLogStream $startApplicationLogStream,
    ) {}

    /**
     * @return Result
     */
    public function start(string $instance, Request $request): array
    {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return ApplicationLogHttpResponses::failure(
                'authorization_failed',
                'Peer identity unknown.',
                [],
                403,
            );
        }

        $resolved = $this->targets->resolveForStream($instance, $request);

        if (array_key_exists('response', $resolved)) {
            /** @var JsonResponse $response */
            $response = $resolved['response'];

            return ApplicationLogHttpResponses::result($response);
        }

        /** @var Instance $targetInstance */
        $targetInstance = $resolved['instance'];
        /** @var Node $serving */
        $serving = $resolved['serving'];
        /** @var string $selector */
        $selector = $resolved['selector'];
        /** @var int $lines */
        $lines = $resolved['lines'];

        $authorization = $this->authorizer->authorize($caller, $serving, 'instance:read');

        if (! $authorization->allowed) {
            return ApplicationLogHttpResponses::failure(
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

        $app = $resolved['app'] ?? null;

        if (! $app instanceof App) {
            return ApplicationLogHttpResponses::failure(
                'instance.not_found',
                "Instance '{$instance}' not found.",
                ['instance' => $instance],
                404,
            );
        }

        return $this->beginStream($app, $request, $targetInstance, $selector, $lines);
    }

    /**
     * @return Result
     */
    private function beginStream(
        App $app,
        Request $request,
        Instance $targetInstance,
        string $selector,
        int $lines,
    ): array {
        try {
            $target = $this->startApplicationLogStream->forInstance(
                app: $app,
                instance: $targetInstance,
                lines: $lines,
                nodeConstraint: ApplicationLogHttpResponses::optionalString($request, 'node'),
                gatewayUrl: ApplicationLogHttpResponses::gatewayUrl($request),
            );
        } catch (GatewayApiException $exception) {
            return ApplicationLogHttpResponses::result(
                ApplicationLogHttpResponses::error(
                    $exception->errorCode() ?? 'validation_failed',
                    $exception->getMessage(),
                    $exception->errorMeta(),
                    422,
                ),
                properties: ApplicationLogActivityProperties::forInstance([
                    'request' => $request,
                    'selector' => $selector,
                    'target' => null,
                    'mode' => 'follow',
                    'lines' => $lines,
                    'outcome' => $exception->errorCode() ?? 'validation_failed',
                ]),
            );
        }

        ApplicationLogHttpResponses::scheduleFollow($this->startApplicationLogStream, $target);

        $operationRunId = (string) ($target['operation_stream']['operation_uuid'] ?? '');

        return ApplicationLogHttpResponses::result(
            ApplicationLogHttpResponses::acceptedStream($operationRunId),
            subject: $targetInstance,
            properties: ApplicationLogActivityProperties::forInstance([
                'request' => $request,
                'selector' => $selector,
                'target' => [
                    'type' => 'instance',
                    'app' => $app->name,
                    'instance' => $targetInstance->name,
                    'workspace' => null,
                    'selector' => $selector,
                ],
                'mode' => 'follow',
                'lines' => $lines,
                'outcome' => 'success',
            ]),
        );
    }
}
