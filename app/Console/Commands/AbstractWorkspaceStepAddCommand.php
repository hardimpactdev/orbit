<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\AddWorkspaceStepRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepMutationResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\text;

abstract class AbstractWorkspaceStepAddCommand extends Command
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

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'App-node callers cannot manage workspace step policy.',
                meta: ['caller_role' => 'app'],
            );
        }

        $command = $this->resolveStepCommand();

        if ($command === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Command is required.',
                meta: ['field' => 'command'],
            );
        }

        $timeout = $this->parseTimeout();

        if ($timeout === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Timeout must be a positive integer.',
                meta: [
                    'field' => 'timeout',
                    'reason' => 'must_be_positive_integer',
                ],
            );
        }

        $before = $this->parseOptionalPositiveInt('before');
        $after = $this->parseOptionalPositiveInt('after');

        if ($before === false) {
            return $this->failCommand('validation_failed', 'The --before option must be a positive integer.', ['field' => 'before']);
        }

        if ($after === false) {
            return $this->failCommand('validation_failed', 'The --after option must be a positive integer.', ['field' => 'after']);
        }

        if (is_int($before) && is_int($after)) {
            return $this->failCommand(
                code: 'workspace.invalid_position',
                message: 'Both insertion flags cannot be supplied.',
                meta: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        $app = $this->stringOption('app') ?? $this->resolveAppFromMarker();
        $path = $app === null ? (realpath((string) getcwd()) ?: (string) getcwd()) : null;

        try {
            $response = $this->storeStep($app, $path, $command, $timeout, is_int($before) ? $before : null, is_int($after) ? $after : null, $callerRole, $payload);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : "Gateway connection is required to add {$this->phaseLabel()} steps.",
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: "Gateway connection is required to add {$this->phaseLabel()} steps.",
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($response);
        }

        $this->renderHuman($response['step']);

        return self::SUCCESS;
    }

    /**
     * @return array{result: array<string, mixed>, step: array<string, mixed>, meta: array<string, mixed>}
     */
    private function storeStep(
        ?string $app,
        ?string $path,
        string $command,
        int $timeout,
        ?int $before,
        ?int $after,
        string $callerRole,
        WorkspaceStepListPayload $payload,
    ): array {
        if ($callerRole !== 'gateway') {
            /** @var WorkspaceStepMutationResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new AddWorkspaceStepRequest(
                    phase: $this->phase(),
                    command: $command,
                    timeout: $timeout,
                    app: $app,
                    path: $path,
                    before: $before,
                    after: $after,
                ))
                ->dto();

            return [
                'result' => $dto->result,
                'step' => $dto->step,
                'meta' => $dto->meta,
            ];
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

            throw new GatewayApiException("App '{$app}' not found.", 'workspace.app_not_found', ['app' => $app]);
        }

        $anchorId = $before ?? $after;

        if ($anchorId !== null && ! $this->anchorExists($model, $anchorId)) {
            throw new GatewayApiException(
                "Referenced insertion step '{$anchorId}' not found for app '{$model->name}' in phase '{$this->phase()->value}'.",
                'workspace.step_not_found',
                [
                    'id' => $anchorId,
                    'app' => $model->name,
                    'phase' => $this->phase()->value,
                ],
            );
        }

        $step = WorkspaceStep::createOrdered(
            appId: $model->id,
            phase: $this->phase(),
            command: $command,
            timeoutSeconds: $timeout,
            beforeStepId: $before,
            afterStepId: $after,
        );

        return [
            'result' => ['action' => 'added'],
            'step' => $payload->forStep($step),
            'meta' => [],
        ];
    }

    private function resolveStepCommand(): ?string
    {
        $command = $this->stringOption('command');

        if ($command !== null) {
            return $command;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Command', required: true));
        }

        return null;
    }

    private function parseTimeout(): ?int
    {
        $value = $this->option('timeout');

        if ($value === null || $value === '') {
            return WorkspaceStep::DEFAULT_TIMEOUT_SECONDS;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function parseOptionalPositiveInt(string $option): int|false|null
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }

    private function anchorExists(App $app, int $stepId): bool
    {
        return WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $this->phase())
            ->whereKey($stepId)
            ->exists();
    }

    private function resolveLocalAppBySlug(string $app): ?App
    {
        return App::query()->where('name', $app)->first();
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
     * @param  array<string, mixed>  $step
     */
    private function renderHuman(array $step): void
    {
        $this->line(ucfirst($this->phaseLabel())." step added for app {$step['app']}.");
        $this->line("ID: {$step['id']}");
        $this->line("Command: {$step['command']}");
        $this->line("Order: {$step['order']}");
        $this->line("Timeout: {$step['timeout_seconds']} seconds");
    }

    /**
     * @param  array{result: array<string, mixed>, step: array<string, mixed>, meta: array<string, mixed>}  $response
     */
    private function jsonSuccess(array $response): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => [
                    'result' => $response['result'],
                    'step' => $response['step'],
                ],
                'meta' => empty($response['meta']) ? (object) [] : $response['meta'],
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
            'validation_failed' => str_contains($message, 'Could not resolve parent app')
                ? 'Could not resolve parent app. Pass `--app=<slug>` or run from inside a registered app or workspace path.'
                : $message,
            'workspace.invalid_position' => 'Both `--before` and `--after` cannot be supplied.',
            'gateway_unavailable' => "Gateway connection is required to add {$this->phaseLabel()} steps.",
            default => $message,
        };
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson()
            && function_exists('posix_isatty')
            && @posix_isatty(STDOUT);
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
