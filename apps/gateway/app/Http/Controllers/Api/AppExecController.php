<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Services\Apps\AppExecutor;
use App\Services\Runtime\OrbitHostCwdResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('app:exec', servingNode: ServingNode::AppOwning)]
final class AppExecController implements Loggable
{
    private ?App $activitySubject = null;

    /** @var list<string> */
    private array $currentCommand = [];

    public function __invoke(string $app, Request $request): JsonResponse
    {
        $this->activitySubject = $this->resolveAppByName($app);
        $this->currentCommand = $this->normalizedCommand($request);

        return $this->dispatch(selector: $app);
    }

    public function byPath(Request $request, OrbitHostCwdResolver $cwdResolver): JsonResponse
    {
        $this->currentCommand = $this->normalizedCommand($request);

        $hostCwd = $this->stringInput($request, 'host_cwd');

        if ($hostCwd === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'host_cwd is required to resolve the app by path.',
                    'meta' => ['field' => 'host_cwd'],
                ],
            ], 422);
        }

        $context = $cwdResolver->resolve($hostCwd);

        if ($context === null) {
            // Unresolvable cwd is a malformed input, not a missing entity.
            // Match the gateway-mode CLI shape and the documented contract.
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "No app matches host cwd '{$hostCwd}'.",
                    'meta' => [
                        'field' => 'host_cwd',
                        'host_cwd' => $hostCwd,
                    ],
                ],
            ], 422);
        }

        if ($context->workspace !== null) {
            // The cwd lives inside a workspace tree. app:exec must not
            // silently fall through to the parent app - mirror the
            // gateway-mode CLI which treats workspace-only matches as
            // "no app match".
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Host cwd '{$hostCwd}' resolves to workspace '{$context->workspace->name}' on app '{$context->app->name}'; call workspace:exec --app={$context->app->name} {$context->workspace->name} instead.",
                    'meta' => [
                        'field' => 'host_cwd',
                        'host_cwd' => $hostCwd,
                        'workspace' => $context->workspace->name,
                        'app' => $context->app->name,
                    ],
                ],
            ], 422);
        }

        $this->activitySubject = $context->app;

        return $this->dispatch(selector: $context->app->name);
    }

    private function dispatch(string $selector): JsonResponse
    {
        $result = app(AppExecutor::class)->execute([
            'app' => $selector,
            'cmd' => $this->currentCommand,
            '--json' => true,
        ]);

        $status = $result->status(fn (array $payload): int => $this->errorStatus($payload));

        return response()->json($result->payload, $status);
    }

    /**
     * @return list<string>
     */
    private function normalizedCommand(Request $request): array
    {
        $raw = $request->input('command');

        if (! is_array($raw)) {
            return [];
        }

        $tokens = [];

        foreach ($raw as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorStatus(array $payload): int
    {
        $code = is_array($payload['error'] ?? null) && is_string($payload['error']['code'] ?? null)
            ? $payload['error']['code']
            : null;

        return match ($code) {
            'app.not_found' => 404,
            'app.exec_node_unreachable',
            'app.exec_docker_unavailable' => 502,
            // Note: app.exec_command_not_executable (126) and
            // app.exec_command_not_found (127) fall through to 422 below -
            // they are caller-input faults (bad command), not infra.
            null => 500,
            default => 422,
        };
    }

    private function resolveAppByName(string $selector): ?App
    {
        $baseQuery = App::query()->with('node');

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery
            ->where('domain', $selector)
            ->first();
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
        return 'api:POST /apps/{app}/exec';
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
            'command' => $this->currentCommand,
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
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
