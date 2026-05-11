<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\SetupWorkspace;
use App\Actions\Workspaces\SetupWorkspaceProgress;
use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Enums\ActivityLogType;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkspaceSetupController implements Loggable
{
    private ?Workspace $activitySubject = null;

    public function __construct(
        private readonly SetupWorkspace $setupWorkspace,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $validator = validator($request->all(), [
            'name' => ['nullable', 'string'],
            'app' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'starts_with:/'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'] ?? null;
        $appName = $validated['app'] ?? null;
        $path = $validated['path'] ?? null;

        try {
            [$workspace, $app, $node, $isAdoption] = $this->resolveWorkspace($name, $appName, $path);
        } catch (\RuntimeException $e) {
            $field = str_contains($e->getMessage(), 'App') ? 'app' : 'workspace';

            return $this->error(
                $field === 'app' ? 'validation_failed' : 'workspace.not_found',
                $e->getMessage(),
                ['field' => $field],
                422,
            );
        }

        $this->activitySubject = $workspace;

        try {
            $result = $this->setupWorkspace->handle($app, $workspace, $node, $isAdoption);
        } catch (\RuntimeException $e) {
            return $this->error(
                'workspace.enactment_failed',
                $e->getMessage(),
                [
                    'phase' => 'artifacts',
                    'node' => $node->name,
                ],
                422,
            );
        }

        $data = [
            'app' => $result['app'],
            'workspace' => $result['workspace'],
            'node' => $result['node'],
            'url' => $result['url'],
            'action' => $result['action'],
            'setup_steps' => $result['setup_steps'],
            'processes' => $result['processes'],
            'http_probe' => $result['http_probe'],
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

    public function stream(
        Request $request,
        SetupWorkspaceProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $validator = validator($request->all(), [
            'name' => ['nullable', 'string'],
            'app' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'starts_with:/'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'] ?? null;
        $appName = $validated['app'] ?? null;
        $path = $validated['path'] ?? null;

        try {
            [$workspace, $app, $node, $isAdoption] = $this->resolveWorkspace($name, $appName, $path);
        } catch (\RuntimeException $e) {
            $field = str_contains($e->getMessage(), 'App') ? 'app' : 'workspace';

            return $this->error(
                $field === 'app' ? 'validation_failed' : 'workspace.not_found',
                $e->getMessage(),
                ['field' => $field],
                422,
            );
        }

        $this->activitySubject = $workspace;

        return $streams->make(function ($emitter) use ($setupProgress, $workspace, $app, $node, $isAdoption): void {
            $plan = $setupProgress->for($workspace, $app, $node, $isAdoption);
            $exitCode = $plan->runForReporter(app(ProgressReporter::class));

            if ($exitCode !== 0) {
                $failure = $plan->failure() ?? [
                    'code' => 'workspace.enactment_failed',
                    'message' => 'Workspace setup failed.',
                    'meta' => [
                        'phase' => 'artifacts',
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

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveWorkspace(?string $name, ?string $appName, ?string $path): array
    {
        if ($path !== null) {
            return $this->resolveByPath($path, $appName);
        }

        if ($name !== null) {
            return $this->resolveByName($name, $appName);
        }

        throw new \RuntimeException('Workspace name or path is required.');
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveByPath(string $path, ?string $appName): array
    {
        $app = $this->resolveApp($appName);

        if (! $app instanceof App) {
            throw new \RuntimeException('App not found. Pass --app=<name> explicitly.');
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new \RuntimeException("Node not found for app '{$app->name}'.");
        }

        $workspaceName = basename($path);

        $existing = Workspace::query()
            ->with('app.node')
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->first();

        if ($existing instanceof Workspace) {
            $existing->update(['path' => $path]);

            return [$existing, $app, $node, false];
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $workspaceName,
            'path' => $path,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return [$workspace, $app, $node, true];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveByName(string $name, ?string $appName): array
    {
        $query = Workspace::query()
            ->with(['app.node'])
            ->where('name', $name);

        if ($appName !== null) {
            $query->whereHas('app', fn ($q) => $q->where('name', $appName));
        }

        $workspace = $query->first();

        if (! $workspace instanceof Workspace) {
            throw new \RuntimeException("Workspace '{$name}' not found.");
        }

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new \RuntimeException("App not found for workspace '{$name}'.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new \RuntimeException("Node not found for workspace '{$name}'.");
        }

        return [$workspace, $app, $node, false];
    }

    private function resolveApp(?string $appName): ?App
    {
        if ($appName === null) {
            return null;
        }

        return App::query()
            ->with('node')
            ->where('name', $appName)
            ->first();
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /workspaces/setup';
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
