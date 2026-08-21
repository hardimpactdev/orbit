<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspaceLogPayload;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:run:log', servingNode: ServingNode::WorkspaceOwning)]
final readonly class WorkspaceLogController implements Loggable
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(string $run, Request $request, WorkspaceLogPayload $payload): JsonResponse
    {
        if (! ctype_digit($run) || (int) $run < 1) {
            return $this->validationFailed($run);
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $workspaceRun = WorkspaceRun::query()
            ->with(['workspace.app.instances', 'workspace.instance', 'runSteps.step'])
            ->whereKey((int) $run)
            ->first();

        if (! $workspaceRun instanceof WorkspaceRun) {
            return $this->runNotFound((int) $run);
        }

        $workspace = $workspaceRun->workspace;

        if (! $workspace instanceof Workspace) {
            return $this->authorizationFailed('Workspace run owning node could not be resolved.', [
                'run' => $workspaceRun->id,
            ]);
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            return $this->authorizationFailed('Workspace run owning node could not be resolved.', [
                'run' => $workspaceRun->id,
            ]);
        }

        try {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($workspace);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'workspace:run:log');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $authorization, 'workspace:run:log');
        }

        return response()->json([
            'success' => [
                'data' => [
                    'run' => $payload->forRun($workspaceRun),
                ],
                'meta' => [
                    'registry_only' => true,
                ],
            ],
        ]);
    }

    private function validationFailed(string $value): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Workspace run ID is required.',
                'meta' => [
                    'field' => 'run',
                    'value' => $value,
                ],
            ],
        ], 400);
    }

    private function runNotFound(int $id): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.run_not_found',
                'message' => "Workspace run {$id} not found.",
                'meta' => [
                    'id' => $id,
                ],
            ],
        ], 404);
    }

    private function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], 403);
    }

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->authorizationFailed(
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /workspaces/runs/{run}/log';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
