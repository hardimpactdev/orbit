<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Enums\WorkspaceLifecyclePhase;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:read', servingNode: ServingNode::AppOwning)]
final readonly class WorkspaceStepListController implements Loggable
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private WorkspaceRoleGuard $workspaceRoleGuard,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    public function __invoke(string $phase, Request $request, WorkspaceStepListPayload $payload): JsonResponse
    {
        $phaseEnum = WorkspaceLifecyclePhase::tryFrom($phase);

        if (! $phaseEnum instanceof WorkspaceLifecyclePhase) {
            return $this->validationFailed('phase', $phase, 'Unsupported workspace step phase.');
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $appSlug = $this->stringQuery($request, 'instance');
        $path = $this->stringQuery($request, 'path');

        if ($appSlug === null && $path === null) {
            return $this->validationFailed('instance', null, 'Could not resolve an instance.');
        }

        try {
            $selection = $appSlug !== null
                ? $this->appSelectorResolver->resolve($appSlug)
                : $this->appSelectorResolver->resolveByPath($path);
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->appSelectionFailed($exception);
        }

        if (! $selection instanceof AppSelection) {
            return $this->appNotFound($appSlug ?? (string) $path);
        }

        $app = $selection->app;
        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            return $this->validationFailed(
                'instance',
                $app->name,
                'Workspace steps require a concrete instance. Use a dotted selector such as hauser.nmbp.',
                'instance_required',
            );
        }

        $servingNode = $this->servingNodeForSelection($selection);

        try {
            $this->workspaceRoleGuard->ensureNodeSupportsWorkspaces($app, $servingNode);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        if (! $servingNode instanceof Node) {
            return $this->authorizationFailed("Could not resolve owning node for project '{$app->name}'.", [
                'project' => $app->name,
            ]);
        }

        $authorization = $this->authorizer->authorize($caller, $servingNode, 'workspace:read');

        if (! $authorization->allowed) {
            return $this->forbidden($servingNode, $authorization, 'workspace:read');
        }

        return response()->json([
            'success' => [
                'data' => [
                    'steps' => $payload->forApp($app, $phaseEnum, $instance),
                ],
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function servingNodeForSelection(AppSelection $selection): ?Node
    {
        $selection->app->loadMissing('node');

        if ($selection->instance !== null) {
            $node = $this->workspacePlacement->nodeForInstance($selection->instance);

            if ($node instanceof Node) {
                return $node;
            }
        }

        return $selection->app->node;
    }

    private function validationFailed(
        string $field,
        ?string $value,
        string $message,
        ?string $reason = null,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => array_filter(
                    [
                        'field' => $field,
                        'value' => $value,
                        'reason' => $reason ?? ($field === 'instance' ? 'missing_required_input' : null),
                    ],
                    fn (mixed $item): bool => $item !== null,
                ),
            ],
        ], 400);
    }

    private function appNotFound(string $instance): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.instance_not_found',
                'message' => "Instance '{$instance}' not found.",
                'meta' => [
                    'instance' => $instance,
                ],
            ],
        ], 404);
    }

    private function appSelectionFailed(AppSelectionResolutionFailed $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 400);
    }

    private function workspaceUnsupportedForProduction(WorkspaceUnsupportedForProduction $exception): JsonResponse
    {
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /workspaces/steps/{phase}';
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
