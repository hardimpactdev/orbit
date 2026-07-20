<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\RemoveWorkspaceStep;
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
use App\Models\WorkspaceStep;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Services\Workspaces\WorkspaceStepListPayload;
use App\Services\Workspaces\WorkspaceStepPolicyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:write', servingNode: ServingNode::AppOwning)]
final class WorkspaceStepDeleteController implements Loggable
{
    private ?WorkspaceStep $activitySubject = null;

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
        private readonly RemoveWorkspaceStep $removeWorkspaceStep,
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly WorkspacePlacement $workspacePlacement,
        private readonly WorkspaceStepPolicyService $stepPolicy,
    ) {}

    public function __invoke(
        string $phase,
        int $step,
        Request $request,
        WorkspaceStepListPayload $payload,
    ): JsonResponse {
        $phaseEnum = WorkspaceLifecyclePhase::tryFrom($phase);

        if (! $phaseEnum instanceof WorkspaceLifecyclePhase) {
            return $this->validationFailed('phase', 'Unsupported workspace step phase.');
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->validationFailed('force', 'Use --force to remove this workspace step.');
        }

        $appSlug = $this->stringValue($request, 'instance');
        $path = $this->stringValue($request, 'path');

        if ($appSlug === null && $path === null) {
            return $this->validationFailed('instance', 'Could not resolve an instance.', [
                'reason' => 'missing_required_input',
            ]);
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

        $authorization = $this->authorizer->authorize($caller, $servingNode, 'workspace:write');

        if (! $authorization->allowed) {
            return $this->forbidden($servingNode, $authorization, 'workspace:write');
        }

        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            return $this->appInstanceRequired();
        }

        $model = $this->stepPolicy->findInstanceStep($app, $phaseEnum, $step, $instance);

        if (! $model instanceof WorkspaceStep) {
            return $this->stepNotFound($step, $app->name, $phaseEnum);
        }

        $removed = $payload->forStep($model, $app);
        $this->activitySubject = $model;

        $this->removeWorkspaceStep->handle($model);

        $remaining = $this->stepPolicy->remainingInstanceCount($app, $phaseEnum, $instance);

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'removed'],
                    'step' => $removed,
                ],
                'meta' => [
                    'remaining_step_count' => $remaining,
                    'new_step_count' => $remaining,
                ],
            ],
        ]);
    }

    private function stringValue(Request $request, string $key): ?string
    {
        $value = $request->query($key, $request->input($key));

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

    private function appInstanceRequired(): JsonResponse
    {
        return $this->validationFailed(
            'instance',
            'Workspace steps can only be changed on instances. Use a dotted selector such as hauser.nmbp.',
            ['reason' => 'instance_required'],
        );
    }

    private function validationFailed(string $field, string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => array_merge(['field' => $field], $meta),
            ],
        ], 400);
    }

    private function stepNotFound(int $id, string $app, WorkspaceLifecyclePhase $phase): JsonResponse
    {
        $label = ucfirst($phase->value);

        return response()->json([
            'error' => [
                'code' => 'workspace.step_not_found',
                'message' => "{$label} step '{$id}' not found for project '{$app}' in phase '{$phase->value}'.",
                'meta' => [
                    'step_id' => $id,
                    'project' => $app,
                    'phase' => $phase->value,
                ],
            ],
        ], 404);
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
        return ActivityLogType::Destructive;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:DELETE /workspaces/steps/{phase}/{step}';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
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
