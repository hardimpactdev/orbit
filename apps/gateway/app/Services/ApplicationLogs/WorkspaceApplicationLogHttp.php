<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Actions\ApplicationLogs\ShowApplicationLog;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * HTTP resolution for GET /api/workspaces/{workspace}/log.
 *
 * @phpstan-type Result array{response: JsonResponse, subject: ?Model, properties: array<string, mixed>}
 */
final readonly class WorkspaceApplicationLogHttp
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private WorkspaceApplicationLogTarget $targets,
        private ShowApplicationLog $showApplicationLog,
    ) {}

    /**
     * @return Result
     */
    public function show(string $workspace, Request $request): array
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
            missingInstanceMessage: 'The instance query parameter is required.',
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

        return $this->read($match, $workspace, $request, $lines);
    }

    /**
     * @return Result
     */
    private function read(Workspace $match, string $workspace, Request $request, int $lines): array
    {
        try {
            $result = $this->showApplicationLog->forWorkspace(
                workspace: $match,
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
                        'authorization_failed' => 403,
                        'application_log.read_failed' => 502,
                        default => 422,
                    },
                ),
                properties: ApplicationLogActivityProperties::forWorkspace([
                    'request' => $request,
                    'workspace' => $workspace,
                    'target' => null,
                    'mode' => 'bounded',
                    'lines' => $lines,
                    'outcome' => $exception->errorCode() ?? 'validation_failed',
                ]),
            );
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        /** @var array<string, mixed>|null $target */
        $target = is_array($data['target'] ?? null) ? $data['target'] : null;

        return ApplicationLogHttpResponses::result(
            response()->json([
                'success' => [
                    'data' => $result['data'],
                    'meta' => $result['meta'],
                ],
            ]),
            subject: $match,
            properties: ApplicationLogActivityProperties::forWorkspace([
                'request' => $request,
                'workspace' => $workspace,
                'target' => $target,
                'mode' => 'bounded',
                'lines' => $lines,
                'outcome' => 'success',
            ]),
        );
    }
}
