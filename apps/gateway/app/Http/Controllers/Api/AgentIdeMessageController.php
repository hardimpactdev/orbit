<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Requests\Api\SendAgentIdeMessageApiRequest;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AgentIde\AgentIdeMessageDelivery;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Orbit\Sdk\Laravel\GatewayApiException;

final class AgentIdeMessageController implements Loggable
{
    private ?Model $activitySubject = null;

    /**
     * @var array<string, mixed>
     */
    private array $activityProperties = [];

    public function __construct(
        private readonly AgentIdeMessageDelivery $delivery,
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly WorkspacePlacement $placement,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(SendAgentIdeMessageApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->error(
                code: 'authorization_failed',
                message: 'Peer identity unknown.',
                meta: [],
                status: 403,
            );
        }

        $workspaceSelector = $request->workspaceSelector();

        if ($workspaceSelector !== null) {
            if ($boundary = $this->workspaceBoundaryForCaller($caller)) {
                return $boundary;
            }

            return $this->sendWorkspaceMessage($request, $caller, $workspaceSelector);
        }

        $pathSelector = $request->pathSelector();

        if ($pathSelector !== null) {
            if ($boundary = $this->workspaceBoundaryForCaller($caller)) {
                return $boundary;
            }

            return $this->sendPathMessage($request, $caller, $pathSelector);
        }

        $instanceSelector = $request->instanceSelector();
        $selection = $this->appSelectorResolver->resolve($instanceSelector);

        if ($selection === null) {
            return $this->error(
                code: 'target_not_found',
                message: "Instance '{$instanceSelector}' not found or not visible.",
                meta: ['instance' => $instanceSelector],
                status: 404,
            );
        }

        try {
            $selection = $this->appSelectorResolver->requireInstance($selection);
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
                status: 422,
            );
        }

        assert($selection->instance instanceof AppInstance);

        return $this->sendInstanceMessage($request, $caller, $selection->instance);
    }

    private function sendPathMessage(SendAgentIdeMessageApiRequest $request, Node $caller, string $path): JsonResponse
    {
        $workspace = $this->resolveWorkspaceFromPath($path);

        if ($workspace instanceof Workspace) {
            return $this->sendWorkspaceMessage($request, $caller, $workspace->name);
        }

        $selection = $this->appSelectorResolver->resolveByPath($path);

        if ($selection !== null) {
            try {
                $selection = $this->appSelectorResolver->requireInstance($selection);
            } catch (AppSelectionResolutionFailed $exception) {
                return $this->error(
                    code: $exception->errorCode,
                    message: $exception->getMessage(),
                    meta: $exception->meta,
                    status: 422,
                );
            }

            assert($selection->instance instanceof AppInstance);

            return $this->sendInstanceMessage($request, $caller, $selection->instance);
        }

        return $this->error(
            code: 'validation_failed',
            message: 'Run this command from an instance/workspace directory or pass --instance/--workspace.',
            meta: ['field' => 'target'],
            status: 422,
        );
    }

    private function sendInstanceMessage(
        SendAgentIdeMessageApiRequest $request,
        Node $caller,
        AppInstance $instance,
    ): JsonResponse {
        $instance->loadMissing('project');
        $project = $instance->project;
        $authorizationMeta = $this->messageAuthorizationMeta($caller, $instance);

        if ($authorizationMeta !== null) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message instance '{$project->name}.{$instance->name}'.",
                meta: $authorizationMeta,
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToInstance($instance, $request->messageBody());
            $this->rememberDeliveryActivity($project, $data);
        } catch (GatewayApiException $e) {
            $this->rememberFailureActivity($project, $e);

            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
                data: $e->errorData(),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function sendWorkspaceMessage(
        SendAgentIdeMessageApiRequest $request,
        Node $caller,
        string $workspaceSelector,
    ): JsonResponse {
        $workspace = $this->resolveWorkspace($workspaceSelector);

        if (! $workspace instanceof Workspace) {
            return $this->error(
                code: 'target_not_found',
                message: "Workspace '{$workspaceSelector}' not found or not visible.",
                meta: ['workspace' => $workspaceSelector],
                status: 404,
            );
        }

        $project = $workspace->project;
        $instance = $workspace->appInstance;

        if (! $project instanceof Project || ! $instance instanceof AppInstance) {
            return $this->error(
                code: 'target_not_found',
                message: "Workspace '{$workspaceSelector}' not found or not visible.",
                meta: ['workspace' => $workspaceSelector],
                status: 404,
            );
        }

        try {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($workspace);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $authorizationMeta = $this->messageAuthorizationMeta($caller, $instance, $workspace);

        if ($authorizationMeta !== null) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message workspace '{$workspace->name}'.",
                meta: $authorizationMeta,
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToWorkspace($workspace->name, $request->messageBody());
            $this->rememberDeliveryActivity($workspace, $data);
        } catch (GatewayApiException $e) {
            $this->rememberFailureActivity($workspace, $e);

            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
                data: $e->errorData(),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function workspaceBoundaryForCaller(Node $caller): ?JsonResponse
    {
        try {
            $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        return null;
    }

    private function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return $this->error(
            code: $exception->errorCode(),
            message: $exception->getMessage(),
            meta: $exception->meta,
            status: 422,
        );
    }

    private function resolveWorkspace(string $selector): ?Workspace
    {
        $matches = Workspace::query()
            ->with(['project', 'appInstance'])
            ->where('name', $selector)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveWorkspaceFromPath(string $path): ?Workspace
    {
        $normalizedPath = rtrim(realpath($path) ?: $path, '/');

        return Workspace::query()
            ->with(['project', 'appInstance'])
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim(realpath($workspace->path) ?: $workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function messageAuthorizationMeta(
        Node $caller,
        AppInstance $instance,
        ?Workspace $workspace = null,
    ): ?array {
        $instance->loadMissing('project');
        $project = $instance->project;
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            return array_filter(
                [
                    'project' => $project->name,
                    'instance' => $instance->name,
                    'workspace' => $workspace?->name,
                    'reason' => 'serving_node_unresolved',
                    'missing_permission' => 'agent-ide:message',
                ],
                static fn (mixed $value): bool => $value !== null,
            );
        }

        $result = $this->authorizer->authorize($caller, $node, 'agent-ide:message');

        if ($result->allowed) {
            return null;
        }

        return array_filter(
            [
                'project' => $project->name,
                'instance' => $instance->name,
                'workspace' => $workspace?->name,
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $node->name,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  array{agent_ide: array<string, mixed>}  $data
     */
    private function rememberDeliveryActivity(Model $subject, array $data): void
    {
        $agentIde = $data['agent_ide'];
        $target = $agentIde['target'] ?? [];

        $this->activitySubject = $subject;
        $this->activityProperties = [
            'target_project' => is_array($target) ? $target['project'] ?? null : null,
            'target_instance' => is_array($target) ? $target['instance'] ?? null : null,
            'target_workspace' => is_array($target) ? $target['workspace'] ?? null : null,
            'adapter' => $agentIde['adapter'] ?? null,
            'source' => $agentIde['source'] ?? null,
            'delivery_status' => $agentIde['delivery']['status'] ?? 'sent',
        ];
    }

    private function rememberFailureActivity(Model $subject, GatewayApiException $exception): void
    {
        $meta = $exception->errorMeta();

        $this->activitySubject = $subject;
        $this->activityProperties = [
            'target_project' => $meta['project'] ?? null,
            'target_instance' => $meta['instance'] ?? null,
            'target_workspace' => $meta['workspace'] ?? null,
            'adapter' => $meta['adapter'] ?? null,
            'delivery_status' => 'failed',
            'failure_code' => $exception->errorCode(),
        ];
    }

    private function statusFor(?string $code): int
    {
        return match ($code) {
            'target_not_found' => 404,
            'no_effective_adapter', 'no_active_session' => 422,
            default => 500,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json([
            'error' => $error,
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /agent-ide/message';
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
        return $this->activityProperties;
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
        $targetProject = $this->activityProperties['target_project'] ?? null;
        $targetInstance = $this->activityProperties['target_instance'] ?? null;
        $targetWorkspace = $this->activityProperties['target_workspace'] ?? null;
        $adapter = $this->activityProperties['adapter'] ?? null;

        if (
            ! is_string($targetProject)
            || $targetProject === ''
            || ! is_string($targetInstance)
            || $targetInstance === ''
            || ! is_string($adapter)
            || $adapter === ''
        ) {
            return null;
        }

        $instance = "{$targetProject}.{$targetInstance}";
        $target = is_string($targetWorkspace) && $targetWorkspace !== ''
            ? "{$instance}/{$targetWorkspace}"
            : $instance;

        if (($this->activityProperties['delivery_status'] ?? null) === 'failed') {
            return "Agent IDE message failed for {$target} through {$adapter}";
        }

        return "Agent IDE message sent to {$target} through {$adapter}";
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
