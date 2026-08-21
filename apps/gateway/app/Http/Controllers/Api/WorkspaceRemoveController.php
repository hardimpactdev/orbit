<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\RemoveWorkspace;
use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:remove', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceRemoveController implements Loggable
{
    private ?Workspace $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function __invoke(string $name, Request $request, RemoveWorkspace $removeWorkspace): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error(
                'validation_failed',
                'Use --force to remove this workspace.',
                ['field' => 'force'],
                422,
            );
        }

        $app = $this->stringQuery($request, 'instance');
        $selection = null;

        if ($app !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($app);
            } catch (AppSelectionResolutionFailed) {
                return $this->error(
                    'workspace.not_found',
                    "Workspace '{$name}' not found in registry.",
                    ['name' => $name, 'instance' => $app],
                    404,
                );
            }
        }

        $matches = $this->matchingWorkspaces($name, $selection);

        if ($matches->isEmpty()) {
            return $this->error(
                'workspace.not_found',
                "Workspace '{$name}' not found in registry.",
                array_filter(
                    [
                        'name' => $name,
                        'instance' => $app,
                    ],
                    fn (?string $value): bool => $value !== null,
                ),
                404,
            );
        }

        if ($app === null && $matches->count() > 1) {
            return $this->error(
                'workspace.ambiguous_name',
                "Workspace name '{$name}' matches multiple instances.",
                [
                    'name' => $name,
                ],
                400,
            );
        }

        $workspace = $matches->firstOrFail();

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            return $this->error(
                'authorization_failed',
                'Workspace owning node could not be resolved.',
                [
                    'name' => $workspace->name,
                    'app' => $workspace->app?->name,
                    'instance' => $workspace->instance->name,
                ],
                403,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'workspace:remove');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $authorization, 'workspace:remove');
        }

        $this->activitySubject = $workspace;
        $result = $removeWorkspace->handle(
            workspace: $workspace,
            keepFiles: $request->boolean('keep_files'),
        );
        $meta = [
            'kept_files' => $result['kept_files'],
        ];

        if ($result['warnings'] !== []) {
            $meta['warnings'] = $result['warnings'];
        }

        unset($result['kept_files'], $result['warnings']);

        return response()->json([
            'success' => [
                'data' => $result,
                'meta' => $meta,
            ],
        ]);
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function matchingWorkspaces(string $name, ?AppSelection $selection): Collection
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->with(['app.instances', 'instance', 'app.processes'])
            ->where('name', $name)
            ->when($selection instanceof AppSelection, fn (Builder $query): Builder => $query->where(
                'app_id',
                $selection?->app->id,
            ))
            ->get();

        /** @var Collection<int, Workspace> $matches */
        $matches = $workspaces
            ->filter(
                fn (Workspace $workspace, int $key): bool => ! $selection instanceof AppSelection
                || $this->appSelectorResolver->matchesWorkspace($workspace, $selection),
            )
            ->values();

        return $matches;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /workspaces/{name}';
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
            'name' => request()->route('name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
