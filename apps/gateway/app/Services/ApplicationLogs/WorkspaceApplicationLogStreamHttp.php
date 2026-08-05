<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Actions\ApplicationLogs\StartApplicationLogStream;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * HTTP resolution for POST /api/workspaces/{workspace}/log-stream.
 *
 * @phpstan-type Result array{response: JsonResponse, subject: ?Model, properties: array<string, mixed>}
 */
final readonly class WorkspaceApplicationLogStreamHttp
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private WorkspaceApplicationLogTarget $targets,
        private StartApplicationLogStream $startApplicationLogStream,
    ) {}

    /**
     * @return Result
     */
    public function start(string $workspace, Request $request): array
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

        $resolved = $this->targets->resolve(
            $workspace,
            $request,
            missingInstanceMessage: 'The instance field is required.',
        );

        if (array_key_exists('response', $resolved)) {
            /** @var JsonResponse $response */
            $response = $resolved['response'];

            return ApplicationLogHttpResponses::result($response);
        }

        /** @var Workspace $match */
        $match = $resolved['workspace'];
        /** @var Node $serving */
        $serving = $resolved['serving'];
        /** @var int $lines */
        $lines = $resolved['lines'];

        $authorization = $this->authorizer->authorize($caller, $serving, 'workspace:read');

        if (! $authorization->allowed) {
            return ApplicationLogHttpResponses::failure(
                'authorization_failed',
                "This node is not authorized for 'workspace:read' on '{$serving->name}'.",
                [
                    'reason' => $authorization->reason,
                    'missing_permission' => $authorization->missingPermission ?? 'workspace:read',
                    'serving_node' => $serving->name,
                ],
                403,
            );
        }

        $appName = $resolved['appName'] ?? null;
        $instanceName = $resolved['instanceName'] ?? null;

        if (! is_string($appName)) {
            return ApplicationLogHttpResponses::failure(
                'workspace.not_found',
                "Workspace '{$workspace}' not found.",
                ['workspace' => $workspace],
                404,
            );
        }

        $instanceLabel = is_string($instanceName) ? $instanceName : null;

        return $this->beginStream([
            'match' => $match,
            'workspace' => $workspace,
            'request' => $request,
            'appName' => $appName,
            'instanceName' => $instanceLabel,
            'lines' => $lines,
        ]);
    }

    /**
     * @param  array{
     *     match: Workspace,
     *     workspace: string,
     *     request: Request,
     *     appName: string,
     *     instanceName: ?string,
     *     lines: int
     * }  $context
     * @return Result
     */
    private function beginStream(array $context): array
    {
        $match = $context['match'];
        $workspace = $context['workspace'];
        $request = $context['request'];
        $lines = $context['lines'];

        try {
            $target = $this->startApplicationLogStream->forWorkspace(
                workspace: $match,
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
                properties: ApplicationLogActivityProperties::forWorkspace([
                    'request' => $request,
                    'workspace' => $workspace,
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
            subject: $match,
            properties: ApplicationLogActivityProperties::forWorkspace([
                'request' => $request,
                'workspace' => $workspace,
                'target' => [
                    'type' => 'workspace',
                    'app' => $context['appName'],
                    'instance' => $context['instanceName'],
                    'workspace' => $workspace,
                    'selector' => $workspace,
                ],
                'mode' => 'follow',
                'lines' => $lines,
                'outcome' => 'success',
            ]),
        );
    }
}
