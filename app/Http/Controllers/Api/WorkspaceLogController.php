<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspaceLogPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class WorkspaceLogController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
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
            ->with(['workspace.app.node', 'runSteps.step'])
            ->whereKey((int) $run)
            ->first();

        if (! $workspaceRun instanceof WorkspaceRun) {
            return $this->runNotFound((int) $run);
        }

        if (! $this->canSeeRun($caller, $workspaceRun)) {
            $workspace = $workspaceRun->workspace;

            return $this->authorizationFailed(
                "This caller is not authorized to read logs for workspace '{$workspace?->name}'.",
                [
                    'workspace' => $workspace?->name,
                    'app' => $workspace?->app?->name,
                ],
            );
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

    private function canSeeRun(Node $caller, WorkspaceRun $run): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        $nodeId = $run->workspace?->app?->node_id;

        if (! is_int($nodeId)) {
            return false;
        }

        return DB::table('node_access')
            ->where('node_access.consumer_node_id', $caller->id)
            ->whereIn('node_access.serving_node_id', $this->hostedAppNodeIds())
            ->where('node_access.serving_node_id', $nodeId)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-development'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-production'),
        ]));
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
        return 'api:GET /workspaces/runs/{run}/log';
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
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
