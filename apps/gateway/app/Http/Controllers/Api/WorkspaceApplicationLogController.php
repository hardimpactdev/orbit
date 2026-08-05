<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ApplicationLogs\ShowApplicationLog;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\ApplicationLogs\ApplicationLogActivityProperties;
use App\Services\ApplicationLogs\ApplicationLogLines;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\GatewayApiException;

#[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceApplicationLogController implements Loggable
{
    private ?Model $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly AppSelectorResolver $selectors,
        private readonly WorkspacePlacement $placement,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(
        string $workspace,
        Request $request,
        ShowApplicationLog $showApplicationLog,
    ): JsonResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $instanceSelector = $this->optionalString($request, 'instance');

        if ($instanceSelector === null) {
            return $this->error(
                'validation_failed',
                'The instance query parameter is required.',
                [
                    'field' => 'instance',
                ],
                422,
            );
        }

        try {
            $lines = ApplicationLogLines::fromRequest($request);
            $selection = $this->selectors->requireInstance(
                $this->selectors->resolveRequired($instanceSelector),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error(
                'validation_failed',
                $exception->getMessage(),
                array_merge(['field' => 'instance'], $exception->meta),
                422,
            );
        } catch (GatewayApiException $exception) {
            return $this->error(
                $exception->errorCode() ?? 'validation_failed',
                $exception->getMessage(),
                $exception->errorMeta(),
                422,
            );
        }

        $match = Workspace::query()
            ->with(['app', 'instance'])
            ->where('name', $workspace)
            ->where('instance_id', $selection->instance?->id)
            ->first();

        if (! $match instanceof Workspace) {
            return $this->error(
                'workspace.not_found',
                "Workspace '{$workspace}' not found.",
                ['workspace' => $workspace, 'instance' => $instanceSelector],
                404,
            );
        }

        try {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($match);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->error($exception->errorCode(), $exception->getMessage(), $exception->meta, 422);
        }

        $serving = $this->placement->nodeForWorkspace($match);

        if (! $serving instanceof Node) {
            return $this->error(
                'validation_failed',
                'The workspace serving node could not be resolved.',
                [
                    'field' => 'workspace',
                ],
                422,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $serving, 'workspace:read');

        if (! $authorization->allowed) {
            return $this->error(
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

        try {
            $result = $showApplicationLog->forWorkspace(
                workspace: $match,
                lines: $lines,
                nodeConstraint: $this->optionalString($request, 'node'),
            );
        } catch (GatewayApiException $exception) {
            $this->recordActivity(
                request: $request,
                workspace: $workspace,
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
                    'authorization_failed' => 403,
                    'application_log.read_failed' => 502,
                    default => 422,
                },
            );
        }

        $this->activitySubject = $match;
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $target = is_array($data['target'] ?? null) ? $data['target'] : null;
        $this->activityProperties = ApplicationLogActivityProperties::forWorkspace(
            request: $request,
            workspace: $workspace,
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
        string $workspace,
        ?array $target,
        string $mode,
        int $lines,
        string $outcome,
    ): void {
        $this->activityProperties = ApplicationLogActivityProperties::forWorkspace(
            request: $request,
            workspace: $workspace,
            target: $target,
            mode: $mode,
            lines: $lines,
            outcome: $outcome,
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
        return 'api:GET /workspaces/{workspace}/log';
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
