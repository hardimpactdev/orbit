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
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

#[RequiresPermission('instance:prune', servingNode: ServingNode::AppInstanceOwning)]
final class AppPruneController implements Loggable
{
    private ?AppInstance $activitySubject = null;

    public function __construct(
        private readonly PruneAppWorkspaces $prune,
        private readonly AppAgentIdeDefaults $defaults,
        private readonly AppSelectorResolver $appSelectorResolver,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'instance' => ['required', 'string'],
            'dry_run' => ['boolean'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();
        $appName = $validated['instance'];
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        if (! is_string($appName)) {
            return $this->error(
                'validation_failed',
                'The instance selector must be a string.',
                ['field' => 'instance'],
                422,
            );
        }

        try {
            $selection = $this->appSelectorResolver->requireInstance(
                $this->appSelectorResolver->resolveRequired($appName),
            );
        } catch (AppSelectionResolutionFailed) {
            return $this->error(
                'instance.not_found',
                "Instance '{$appName}' not found.",
                ['instance' => $appName],
                404,
            );
        }

        $app = $selection->app;
        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            return $this->error(
                'instance.not_found',
                "Instance '{$appName}' not found.",
                ['instance' => $appName],
                404,
            );
        }

        $this->activitySubject = $instance;
        $node = $this->placement->nodeForInstance($instance);

        /** @var mixed $caller */
        $caller = $request->user();

        try {
            if ($caller instanceof Node) {
                $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
            }

            $this->workspaceRoleGuard->ensureNodeSupportsWorkspaces($app, $node);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $effectiveAdapter = $this->defaults->payloadFor($instance, $node)['effective_adapter'];

        if ($effectiveAdapter === null) {
            return $this->error(
                'instance.no_agent_ide_adapter',
                'No agent IDE adapter configured for this instance.',
                ['instance' => $appName],
                422,
            );
        }

        try {
            $result = $this->prune->handle($app, $instance, $dryRun);
        } catch (RuntimeException $e) {
            return $this->error(
                'instance.agent_ide_query_failed',
                $e->getMessage(),
                ['instance' => $appName],
                422,
            );
        }

        $data = [
            'project' => $result['project'],
            'instance' => $result['instance'],
            'stale_workspaces' => $result['stale_workspaces'],
            'dry_run' => $result['dry_run'],
        ];

        $meta = [];

        if ($result['warnings'] !== []) {
            $meta['warnings'] = $result['warnings'];
        }

        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], 200);
    }

    private function error(string $code, string $message, array $meta = [], int $status = 422): JsonResponse
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
            $exception->errorCode(),
            $exception->getMessage(),
            $exception->meta,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /instances/prune';
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
