<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ListWorkspaceStepsRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepListResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Console\Command;
use Throwable;

abstract class AbstractWorkspaceStepListCommand extends Command
{
    abstract protected function phase(): WorkspaceLifecyclePhase;

    abstract protected function phaseLabel(): string;

    public function handle(WorkspaceStepListPayload $payload): int
    {
        $callerRole = app(CallerRoleResolver::class)->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $app = $this->stringOption('app');
        $path = null;

        if ($app === null) {
            $app = $this->resolveAppFromMarker();
        }

        if ($app === null) {
            $path = realpath((string) getcwd()) ?: (string) getcwd();
        }

        try {
            $steps = $this->fetchSteps($app, $path, $callerRole, $payload);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : "Gateway connection is required to list {$this->phaseLabel()} steps.",
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: "Gateway connection is required to list {$this->phaseLabel()} steps.",
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['steps' => $steps]);
        }

        $this->renderHuman($steps, $this->resolvedAppName($steps, $app));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSteps(?string $app, ?string $path, string $callerRole, WorkspaceStepListPayload $payload): array
    {
        if ($callerRole !== 'gateway') {
            /** @var WorkspaceStepListResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new ListWorkspaceStepsRequest(
                    phase: $this->phase(),
                    app: $app,
                    path: $path,
                ))
                ->dto();

            return $dto->steps;
        }

        $model = $app !== null
            ? $this->resolveLocalAppBySlug($app)
            : $this->resolveLocalAppByPath((string) $path);

        if (! $model instanceof App) {
            if ($app === null) {
                throw new GatewayApiException('Could not resolve parent app.', 'validation_failed', [
                    'field' => 'app',
                    'reason' => 'missing_required_input',
                ]);
            }

            throw new GatewayApiException("App '{$app}' not found.", 'workspace.app_not_found', [
                'app' => $app,
            ]);
        }

        return $payload->forApp($model, $this->phase());
    }

    private function resolveLocalAppBySlug(string $app): ?App
    {
        return App::query()
            ->where('name', $app)
            ->first();
    }

    private function resolveLocalAppByPath(string $path): ?App
    {
        $normalizedPath = rtrim($path, '/');

        $app = App::query()
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim(realpath($app->path) ?: $app->path, '/');

                return $normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/");
            });

        if ($app instanceof App) {
            return $app;
        }

        return Workspace::query()
            ->with('app')
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim(realpath($workspace->path) ?: $workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            })?->app;
    }

    private function resolveAppFromMarker(): ?string
    {
        $cwd = realpath((string) getcwd()) ?: (string) getcwd();

        while ($cwd !== '' && $cwd !== '/') {
            $candidate = "{$cwd}/.orbit/config";

            if (is_file($candidate)) {
                $data = json_decode((string) file_get_contents($candidate), true);

                if (is_array($data) && is_string($data['app'] ?? null) && $data['app'] !== '') {
                    return $data['app'];
                }
            }

            $parent = dirname($cwd);

            if ($parent === $cwd) {
                break;
            }

            $cwd = $parent;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function renderHuman(array $steps, ?string $app): void
    {
        $appName = $app ?? 'the resolved app';

        if ($steps === []) {
            $this->line("No {$this->phaseLabel()} steps defined for {$appName}.");

            return;
        }

        $this->line(ucfirst($this->phaseLabel())." steps for {$appName}:");
        $this->newLine();
        $this->table(
            ['ID', 'ORDER', 'COMMAND', 'TIMEOUT'],
            array_map(fn (array $step): array => [
                $step['id'],
                $step['order'],
                $step['command'],
                "{$step['timeout_seconds']}s",
            ], $steps),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolvedAppName(array $steps, ?string $fallback): ?string
    {
        if (isset($steps[0]['app']) && is_string($steps[0]['app'])) {
            return $steps[0]['app'];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($this->humanErrorMessage($code, $message));

        return self::FAILURE;
    }

    private function humanErrorMessage(string $code, string $message): string
    {
        return match ($code) {
            'validation_failed' => 'Could not resolve parent app. Pass `--app=<slug>` or run from inside a registered app or workspace path.',
            'gateway_unavailable' => "Gateway connection is required to list {$this->phaseLabel()} steps.",
            default => $message,
        };
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
