<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Workspace;
use App\Services\Runtime\OrbitHostCwdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

#[RequiresPermission('workspace:exec', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceExecController implements Loggable
{
    private ?Workspace $activitySubject = null;

    /** @var list<string> */
    private array $currentCommand = [];

    public function __invoke(string $name, Request $request): JsonResponse
    {
        $this->currentCommand = $this->normalizedCommand($request);

        $appFilter = $this->stringQuery($request, 'app');
        $matches = $this->matchingWorkspaces($name, $appFilter);

        if ($matches->isEmpty()) {
            return $this->error('workspace.not_found', "Workspace '{$name}' not found.", array_filter([
                'workspace' => $name,
                'app' => $appFilter,
            ], fn ($value): bool => $value !== null), 404);
        }

        if ($appFilter === null && $matches->count() > 1) {
            return $this->error('workspace.ambiguous_name', "Workspace name '{$name}' matches multiple apps.", [
                'workspace' => $name,
                'apps' => $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
            ], 400);
        }

        $workspace = $matches->firstOrFail();
        $this->activitySubject = $workspace;

        return $this->dispatch($workspace);
    }

    public function byPath(Request $request, OrbitHostCwdResolver $cwdResolver): JsonResponse
    {
        $this->currentCommand = $this->normalizedCommand($request);

        $hostCwd = $this->stringInput($request, 'host_cwd');

        if ($hostCwd === null) {
            return $this->error('validation_failed', 'host_cwd is required to resolve the workspace by path.', ['field' => 'host_cwd'], 422);
        }

        $context = $cwdResolver->resolve($hostCwd);

        if ($context?->workspace === null) {
            // Unresolvable cwd (or a cwd that only matches a parent app) is
            // a malformed input, not a missing entity. Match the
            // gateway-mode CLI shape and the documented contract.
            return $this->error(
                'validation_failed',
                "No workspace matches host cwd '{$hostCwd}'.",
                ['field' => 'host_cwd', 'host_cwd' => $hostCwd],
                422,
            );
        }

        $workspace = $context->workspace;
        $workspace->loadMissing('app.node');
        $this->activitySubject = $workspace;

        return $this->dispatch($workspace);
    }

    private function dispatch(Workspace $workspace): JsonResponse
    {
        $exitCode = Artisan::call('workspace:exec', [
            'workspace' => $workspace->name,
            '--app' => $workspace->app?->name,
            'cmd' => $this->currentCommand,
            '--json' => true,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $status = $exitCode === 0 ? 200 : $this->errorStatus($payload);

        return response()->json($payload, $status);
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function matchingWorkspaces(string $name, ?string $app): Collection
    {
        return Workspace::query()
            ->with('app.node')
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();
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

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
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
            'workspace.not_found' => 404,
            'workspace.ambiguous_name' => 400,
            'workspace.exec_node_unreachable',
            'workspace.exec_docker_unavailable' => 502,
            // Note: workspace.exec_command_not_executable (126) and
            // workspace.exec_command_not_found (127) fall through to 422
            // below — they are caller-input faults (bad command), not
            // infra.
            null => 500,
            default => 422,
        };
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
        return 'api:POST /workspaces/{name}/exec';
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
