<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * Resolves instance log target selection, serving node, and line count.
 *
 * @phpstan-type Context array{
 *     lines: int,
 *     app: App,
 *     instance: Instance,
 *     serving: Node,
 *     selector: string
 * }
 * @phpstan-type Failure array{response: JsonResponse}
 */
final readonly class InstanceApplicationLogTarget
{
    public function __construct(
        private AppSelectorResolver $selectors,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return Context|Failure
     */
    public function resolveForShow(string $instance, Request $request): array
    {
        return $this->resolve(
            $instance,
            $request,
            selectionFailure: fn (AppSelectionResolutionFailed $exception): JsonResponse => $this->selectionFailed(
                $exception,
                $instance,
            ),
            servingMeta: ['field' => 'instance', 'instance' => $instance],
        );
    }

    /**
     * @return Context|Failure
     */
    public function resolveForStream(string $instance, Request $request): array
    {
        return $this->resolve(
            $instance,
            $request,
            selectionFailure: static fn (AppSelectionResolutionFailed $_exception): JsonResponse => ApplicationLogHttpResponses::error(
                'instance.not_found',
                "Instance '{$instance}' not found.",
                ['instance' => $instance],
                404,
            ),
            servingMeta: ['field' => 'instance'],
        );
    }

    /**
     * @param  callable(AppSelectionResolutionFailed): JsonResponse  $selectionFailure
     * @param  array<string, mixed>  $servingMeta
     * @return Context|Failure
     */
    private function resolve(
        string $instance,
        Request $request,
        callable $selectionFailure,
        array $servingMeta,
    ): array {
        try {
            $lines = ApplicationLogLines::fromRequest($request);
            $selection = $this->selectors->requireInstance(
                $this->selectors->resolveRequired($instance),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return [
                'response' => $selectionFailure($exception),
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

        $targetInstance = $selection->instance;

        if (! $targetInstance instanceof Instance) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'instance.not_found',
                    "Instance '{$instance}' not found.",
                    ['instance' => $instance],
                    404,
                ),
            ];
        }

        $serving = $this->placement->nodeForInstance($targetInstance);

        if (! $serving instanceof Node) {
            return [
                'response' => ApplicationLogHttpResponses::error(
                    'validation_failed',
                    'The instance serving node could not be resolved.',
                    $servingMeta,
                    422,
                ),
            ];
        }

        return [
            'lines' => $lines,
            'app' => $selection->app,
            'instance' => $targetInstance,
            'serving' => $serving,
            'selector' => "{$selection->app->name}.{$targetInstance->name}",
        ];
    }

    private function selectionFailed(AppSelectionResolutionFailed $exception, string $instance): JsonResponse
    {
        $required =
            $exception->errorCode === 'validation_failed'
            && ($exception->meta['reason'] ?? null) === 'instance_required';

        return ApplicationLogHttpResponses::error(
            $required ? $exception->errorCode : 'instance.not_found',
            $required ? $exception->getMessage() : "Instance '{$instance}' not found.",
            $required ? $exception->meta : ['instance' => $instance],
            $required ? 422 : 404,
        );
    }
}
