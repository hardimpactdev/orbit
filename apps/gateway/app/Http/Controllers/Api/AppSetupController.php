<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\SetupApp;
use App\Actions\Apps\SetupAppProgress;
use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppSetupController implements Loggable
{
    private ?AppInstance $activitySubject = null;

    /** @var array<string, string> */
    private array $activityProperties = [];

    public function __construct(
        private readonly SetupApp $setupApp,
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly AppSelectorResolver $selectorResolver,
        private readonly WorkspacePlacement $placement,
    ) {}

    #[OpenApiResponse(
        status: 200,
        description: 'The app instance setup result.',
        type: "array{success: array{data: array{app: string, app_instance: string, node: string, path: string, url: string, action: 'set_up'|'converged', setup_steps: array{status: string, count: int, message: string}}, meta: list<mixed>}}",
    )]
    #[OpenApiResponse(
        status: 403,
        description: 'The caller is not authorized to set up the app instance.',
        type: 'array{error: array{code: string, message: string, meta: array<string, mixed>}}',
    )]
    #[OpenApiResponse(
        status: 404,
        description: 'The selected app was not found.',
        type: 'array{error: array{code: string, message: string, meta: array<string, mixed>}}',
    )]
    #[OpenApiResponse(
        status: 422,
        description: 'The app instance selector or setup operation is invalid.',
        type: 'array{error: array{code: string, message: string, meta: array<string, mixed>}}',
    )]
    public function __invoke(
        string $app,
        Request $request,
        SetupAppProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        $target = $this->resolveAuthorizedTarget($app, $request);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $targetApp = $target['app'];
        $instance = $target['instance'];
        $node = $target['node'];
        $this->activitySubject = $instance;
        $this->activityProperties = [
            'app' => $targetApp->name,
            'app_instance' => $instance->name,
            'status' => 'pending',
        ];

        if ($this->wantsEventStream($request)) {
            return $this->stream($setupProgress, $streams, $targetApp, $instance, $node);
        }

        try {
            $result = $this->setupApp->handle($targetApp, $instance, $node);
        } catch (RuntimeException $exception) {
            $this->activityProperties['status'] = 'failed';

            return $this->error('app.setup_failed', $exception->getMessage(), [
                'phase' => 'setup',
                'node' => $node->name,
                'app_instance' => $instance->name,
            ]);
        }

        $this->activityProperties['status'] = $result['setup_steps']['status'];

        if ($result['setup_steps']['status'] === 'failed') {
            return $this->error('app.setup_step_failed', $result['setup_steps']['message'], [
                'phase' => 'setup_steps',
                'node' => $node->name,
                'path' => $result['path'],
                'app_instance' => $instance->name,
            ]);
        }

        return response()->json([
            'success' => [
                'data' => $result,
                'meta' => [],
            ],
        ]);
    }

    private function stream(
        SetupAppProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
        App $app,
        AppInstance $instance,
        Node $node,
    ): StreamedResponse {
        return $streams->make(function ($emitter) use ($setupProgress, $app, $instance, $node): void {
            $plan = $setupProgress->for($app, $instance, $node);
            $exitCode = $plan->runForReporter(app(ProgressReporter::class));

            if ($exitCode !== 0) {
                $failure = $plan->failure() ?? [
                    'code' => 'app.setup_failed',
                    'message' => 'App setup failed.',
                    'meta' => [
                        'phase' => 'setup',
                        'node' => $node->name,
                        'app_instance' => $instance->name,
                    ],
                ];

                $emitter->error($failure['message'], 1, [
                    'code' => $failure['code'],
                    'message' => $failure['message'],
                    'meta' => $failure['meta'],
                    'footer' => $plan->failFooter(),
                ]);

                return;
            }

            $emitter->complete(0, [
                'footer' => $plan->doneFooter(),
                'result' => $plan->result(),
            ]);
        });
    }

    /**
     * @return array{app: App, instance: AppInstance, node: Node}|JsonResponse
     */
    private function resolveAuthorizedTarget(string $selector, Request $request): array|JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $instanceIsVisible = fn (AppInstance $instance): bool => $this->selectorResolver->instanceIsVisibleTo(
            $caller,
            $instance,
            'app:write',
        );

        try {
            $selection = $this->selectorResolver->resolve($selector, $instanceIsVisible);

            if ($selection === null) {
                return $this->appNotFound($selector);
            }

            $selection = $this->selectorResolver->requireInstance(
                $selection,
                instanceIsVisible: $instanceIsVisible,
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta);
        }

        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            return $this->instanceUnavailable($selection->app, null);
        }

        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            return $this->instanceUnavailable($selection->app, $instance);
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'app:write');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $instance, $authorization, 'app:write');
        }

        return [
            'app' => $selection->app,
            'instance' => $instance,
            'node' => $node,
        ];
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
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

    private function appNotFound(string $app): JsonResponse
    {
        return $this->error('app.not_found', "App '{$app}' was not found.", ['app' => $app], 404);
    }

    private function instanceUnavailable(App $app, ?AppInstance $instance): JsonResponse
    {
        return $this->error(
            'validation_failed',
            "App instance '{$app->name}.{$instance?->name}' does not resolve an Orbit serving node.",
            [
                'field' => 'app',
                'reason' => 'app_instance_unavailable',
                'app' => $app->name,
                'app_instance' => $instance?->name,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error('authorization_failed', $message, empty($meta) ? [] : $meta, 403);
    }

    private function forbidden(
        Node $servingNode,
        AppInstance $instance,
        AuthorizationResult $result,
        string $permission,
    ): JsonResponse {
        return $this->authorizationFailed(
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
                'app_instance' => $instance->name,
            ],
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /apps/{app}/setup';
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
