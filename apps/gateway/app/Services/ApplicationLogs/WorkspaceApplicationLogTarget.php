<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * Resolves workspace log target selection, serving node, and line count.
 *
 * @phpstan-type Context array{
 *     lines: int,
 *     workspace: Workspace,
 *     serving: Node,
 *     instanceSelector: string,
 *     appName: string,
 *     instanceName: ?string
 * }
 * @phpstan-type Failure array{response: JsonResponse}
 */
final readonly class WorkspaceApplicationLogTarget
{
    public function __construct(
        private AppSelectorResolver $selectors,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * @return Context|Failure
     */
    public function resolve(string $workspace, Request $request, string $missingInstanceMessage): array
    {
        $instanceSelector = ApplicationLogHttpResponses::optionalString($request, 'instance');

        if ($instanceSelector === null) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'validation_failed',
                    $missingInstanceMessage,
                    ['field' => 'instance'],
                    422,
                ),
            ];
        }

        try {
            $lines = ApplicationLogLines::fromRequest($request);
            $selection = $this->selectors->requireInstance(
                $this->selectors->resolveRequired($instanceSelector),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'validation_failed',
                    $exception->getMessage(),
                    array_merge(['field' => 'instance'], $exception->meta),
                    422,
                ),
            ];
        } catch (GatewayApiException $exception) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    $exception->errorCode() ?? 'validation_failed',
                    $exception->getMessage(),
                    $exception->errorMeta(),
                    422,
                ),
            ];
        }

        $match = Workspace::query()
            ->with(['app', 'instance'])
            ->where('name', $workspace)
            ->where('instance_id', $selection->instance?->id)
            ->first();

        if (! $match instanceof Workspace) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'workspace.not_found',
                    "Workspace '{$workspace}' not found.",
                    ['workspace' => $workspace, 'instance' => $instanceSelector],
                    404,
                ),
            ];
        }

        try {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($match);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    $exception->errorCode(),
                    $exception->getMessage(),
                    $exception->meta,
                    422,
                ),
            ];
        }

        $serving = $this->placement->nodeForWorkspace($match);

        if (! $serving instanceof Node) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'validation_failed',
                    'The workspace serving node could not be resolved.',
                    ['field' => 'workspace'],
                    422,
                ),
            ];
        }

        return [
            'lines' => $lines,
            'workspace' => $match,
            'serving' => $serving,
            'instanceSelector' => $instanceSelector,
            'appName' => $selection->app->name,
            'instanceName' => $selection->instance?->name,
        ];
    }
}
