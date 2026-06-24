<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\SetupApp;
use App\Actions\Apps\SetupAppProgress;
use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
final class AppSetupController implements Loggable
{
    private ?App $activitySubject = null;

    public function __construct(
        private readonly SetupApp $setupApp,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(
        string $app,
        Request $request,
        SetupAppProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $targetApp->loadMissing('node');

        if (! $targetApp->node instanceof Node) {
            return $this->authorizationFailed("Could not resolve owning node for app '{$targetApp->name}'.", [
                'app' => $targetApp->name,
            ]);
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $authorization = $this->authorizer->authorize($caller, $targetApp->node, 'app:write');

        if (! $authorization->allowed) {
            return $this->forbidden($targetApp->node, $authorization, 'app:write');
        }

        $this->activitySubject = $targetApp;

        if ($this->wantsEventStream($request)) {
            return $this->stream($setupProgress, $streams, $targetApp, $targetApp->node);
        }

        try {
            $result = $this->setupApp->handle($targetApp);
        } catch (RuntimeException $exception) {
            return $this->error('app.setup_failed', $exception->getMessage(), [
                'phase' => 'setup',
                'node' => $targetApp->node?->name,
            ]);
        }

        if ($result['setup_steps']['status'] === 'failed') {
            return $this->error('app.setup_step_failed', $result['setup_steps']['message'], [
                'phase' => 'setup_steps',
                'node' => $targetApp->node?->name,
                'path' => $targetApp->path,
            ]);
        }

        return response()->json([
            'success' => [
                'data' => $result,
                'meta' => (object) [],
            ],
        ]);
    }

    private function stream(
        SetupAppProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
        App $app,
        Node $node,
    ): StreamedResponse {
        return $streams->make(function ($emitter) use ($setupProgress, $app, $node): void {
            $plan = $setupProgress->for($app, $node);
            $exitCode = $plan->runForReporter(app(ProgressReporter::class));

            if ($exitCode !== 0) {
                $failure = $plan->failure() ?? [
                    'code' => 'app.setup_failed',
                    'message' => 'App setup failed.',
                    'meta' => [
                        'phase' => 'setup',
                        'node' => $node->name,
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

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error('authorization_failed', $message, empty($meta) ? [] : $meta, 403);
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
