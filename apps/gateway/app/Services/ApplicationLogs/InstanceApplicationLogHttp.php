<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Actions\ApplicationLogs\ShowApplicationLog;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * HTTP resolution for GET /api/instances/{instance}/log.
 *
 * @phpstan-type Result array{response: JsonResponse, subject: ?Model, properties: array<string, mixed>}
 */
final readonly class InstanceApplicationLogHttp
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private InstanceApplicationLogTarget $targets,
        private ShowApplicationLog $showApplicationLog,
    ) {}

    /**
     * @return Result
     */
    public function show(string $instance, Request $request): array
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

        $resolved = $this->targets->resolveForShow($instance, $request);

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
            return ApplicationLogHttpResponses::result(
                ApplicationLogHttpResponses::error(
                    'authorization_failed',
                    "This node is not authorized for 'instance:read' on '{$serving->name}'.",
                    [
                        'reason' => $authorization->reason,
                        'missing_permission' => $authorization->missingPermission ?? 'instance:read',
                        'serving_node' => $serving->name,
                    ],
                    403,
                ),
                properties: ApplicationLogActivityProperties::forInstance([
                    'request' => $request,
                    'selector' => $selector,
                    'target' => null,
                    'mode' => 'bounded',
                    'lines' => $lines,
                    'outcome' => 'unauthorized',
                ]),
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

        return $this->read($app, $request, $targetInstance, $selector, $lines);
    }

    /**
     * @return Result
     */
    private function read(
        App $app,
        Request $request,
        Instance $targetInstance,
        string $selector,
        int $lines,
    ): array {
        try {
            $payload = $this->showApplicationLog->forInstance(
                app: $app,
                instance: $targetInstance,
                lines: $lines,
                nodeConstraint: ApplicationLogHttpResponses::optionalString($request, 'node'),
            );
        } catch (GatewayApiException $exception) {
            return ApplicationLogHttpResponses::result(
                ApplicationLogHttpResponses::error(
                    $exception->errorCode() ?? 'validation_failed',
                    $exception->getMessage(),
                    $exception->errorMeta(),
                    match ($exception->errorCode()) {
                        'instance.not_found' => 404,
                        'authorization_failed' => 403,
                        'application_log.read_failed' => 502,
                        default => 422,
                    },
                ),
                properties: ApplicationLogActivityProperties::forInstance([
                    'request' => $request,
                    'selector' => $selector,
                    'target' => null,
                    'mode' => 'bounded',
                    'lines' => $lines,
                    'outcome' => $exception->errorCode() ?? 'validation_failed',
                ]),
            );
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        /** @var array<string, mixed>|null $target */
        $target = is_array($data['target'] ?? null) ? $data['target'] : null;

        return ApplicationLogHttpResponses::result(
            response()->json([
                'success' => [
                    'data' => $payload['data'],
                    'meta' => $payload['meta'],
                ],
            ]),
            subject: $targetInstance,
            properties: ApplicationLogActivityProperties::forInstance([
                'request' => $request,
                'selector' => $selector,
                'target' => $target,
                'mode' => 'bounded',
                'lines' => $lines,
                'outcome' => 'success',
            ]),
        );
    }
}
