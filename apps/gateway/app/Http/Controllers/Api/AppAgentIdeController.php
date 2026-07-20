<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\PruneAppWorkspaces;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\SetAppAgentIdeApiRequest;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use RuntimeException;

#[RequiresPermission('instance:agent', servingNode: ServingNode::AppInstanceOwning)]
final class AppAgentIdeController implements Loggable
{
    private ?AppInstance $activitySubject = null;

    private ?string $activityTargetName = null;

    private ?string $activityAgentIde = null;

    private ?string $activityAction = null;

    public function __construct(
        private readonly AppAgentIdeDefaults $defaults,
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly PruneAppWorkspaces $pruneAppWorkspaces,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(SetAppAgentIdeApiRequest $request, string $instance): JsonResponse
    {
        $selector = $instance;
        $this->activityTargetName = $selector;

        try {
            $selection = $this->appSelectorResolver->requireInstance(
                $this->appSelectorResolver->resolveRequired($selector),
            );
        } catch (AppSelectionResolutionFailed) {
            return $this->error(
                code: 'instance.not_found',
                message: "Instance '{$selector}' not found.",
                meta: ['instance' => $selector],
                status: 404,
            );
        }

        $project = $selection->app;
        $targetInstance = $selection->instance;

        if (! $targetInstance instanceof AppInstance) {
            return $this->error(
                code: 'instance.not_found',
                message: "Instance '{$selector}' not found.",
                meta: ['instance' => $selector],
                status: 404,
            );
        }

        $this->activityTargetName = "{$project->name}.{$targetInstance->name}";

        /** @var mixed $caller */
        $caller = $request->user();

        if ($caller instanceof Node) {
            try {
                $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        $agentIde = $request->agentIde();

        if (! $this->defaults->isSupported($agentIde)) {
            return $this->error(
                code: 'instance.unsupported_adapter',
                message: "The adapter \"{$agentIde}\" is not supported.",
                meta: [
                    'adapter' => $agentIde,
                    'supported' => $this->defaults->supportedAdapters(),
                ],
                status: 422,
            );
        }

        $data = $this->defaults->set($targetInstance, $agentIde);

        if ($data['action'] === 'set') {
            $cleanupResult = $this->maybeCleanupWorkspaces(
                $project,
                $targetInstance,
                $data,
                $request->force(),
            );

            if ($cleanupResult instanceof JsonResponse) {
                return $cleanupResult;
            }

            $data = $cleanupResult;
        }

        $this->activitySubject = $targetInstance->refresh();
        $this->activityAgentIde = $data['agent_ide']['effective_adapter'] ?? $data['agent_ide']['adapter'];
        $this->activityAction = $data['action'];

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    /**
     * @param  array{
     *     instance: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     * @return array{
     *     instance: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }|JsonResponse
     */
    private function maybeCleanupWorkspaces(
        Project $project,
        AppInstance $instance,
        array $data,
        bool $force,
    ): array|JsonResponse {
        $previousAdapter = $data['previous_adapter'];
        $currentEffective = $data['agent_ide']['effective_adapter'];

        if ($previousAdapter === null || $previousAdapter === $currentEffective) {
            return $data;
        }

        try {
            $dryRun = $this->pruneAppWorkspaces->handle(
                $project,
                $instance,
                dryRun: true,
                adapterName: $previousAdapter,
            );
            $staleWorkspaces = $dryRun['stale_workspaces'];

            if ($staleWorkspaces === []) {
                return $data;
            }

            if (! $force) {
                $count = count($staleWorkspaces);

                return $this->error(
                    code: 'workspace_cleanup_consent_required',
                    message: "Destructive workspace cleanup required ({$count} workspace(s) managed by '{$previousAdapter}'). Use force=true to proceed.",
                    meta: [
                        'previous_adapter' => $previousAdapter,
                        'stale_workspaces' => array_map(fn (array $ws): string => $ws['name'], $staleWorkspaces),
                    ],
                    status: 422,
                );
            }

            $result = $this->pruneAppWorkspaces->handle(
                $project,
                $instance,
                dryRun: false,
                adapterName: $previousAdapter,
            );
            $removed = array_values(array_filter(
                $result['stale_workspaces'],
                fn (array $ws): bool => $ws['removed'],
            ));

            $data['cleanup']['workspaces_removed'] = array_map(
                fn (array $ws): string => $ws['name'],
                $removed,
            );
        } catch (RuntimeException) {
            // Adapter does not support workspace discovery; skip cleanup.
        }

        return $data;
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
                'meta' => $meta,
            ],
        ], $status);
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
        return 'api:POST /instances/{instance}/agent-ide';
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
        return [
            'target_instance' => $this->activityTargetName ?? (string) request()->route('instance'),
            'agent_ide' => $this->activityAgentIde,
            'action' => $this->activityAction,
        ];
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
        $target = $this->activityTargetName ?? (string) request()->route('instance');

        if ($target === '' || $this->activityAction === null) {
            return null;
        }

        if ($this->activityAgentIde === null) {
            return "Instance {$target} agent IDE cleared";
        }

        if ($this->activityAction === 'converged') {
            return "Instance {$target} agent IDE already set to {$this->activityAgentIde}";
        }

        return "Instance {$target} agent IDE set to {$this->activityAgentIde}";
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
