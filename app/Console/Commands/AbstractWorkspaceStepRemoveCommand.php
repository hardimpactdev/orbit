<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\RemoveWorkspaceStepRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepMutationResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

abstract class AbstractWorkspaceStepRemoveCommand extends Command
{
    abstract protected function phase(): WorkspaceLifecyclePhase;

    abstract protected function phaseLabel(): string;

    public function handle(WorkspaceStepListPayload $payload): int
    {
        $onGateway = (bool) config('orbit.is_gateway', false);

        $step = $this->resolveStepId();

        if ($step === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Step ID must be a positive integer.',
                meta: [
                    'field' => 'step',
                    'reason' => 'must_be_positive_integer',
                ],
            );
        }

        $app = $this->stringOption('app') ?? $this->resolveAppFromMarker();
        $path = $app === null ? (realpath((string) getcwd()) ?: (string) getcwd()) : null;

        if (! $this->hasDestructiveConsent()) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'This is a destructive operation. Use --force or confirm the prompt.',
                meta: ['field' => 'force'],
            );
        }

        try {
            $response = $this->removeStep($app, $path, $step, $onGateway, $payload);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : "Gateway connection is required to remove {$this->phaseLabel()} steps.",
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: "Gateway connection is required to remove {$this->phaseLabel()} steps.",
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($response);
        }

        $this->renderHuman($response['step'], $response['meta']);

        return self::SUCCESS;
    }

    /**
     * @return array{result: array<string, mixed>, step: array<string, mixed>, meta: array<string, mixed>}
     */
    private function removeStep(
        ?string $app,
        ?string $path,
        int $step,
        bool $onGateway,
        WorkspaceStepListPayload $payload,
    ): array {
        if (! $onGateway) {
            /** @var WorkspaceStepMutationResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new RemoveWorkspaceStepRequest(
                    phase: $this->phase(),
                    step: $step,
                    app: $app,
                    path: $path,
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

        $workspaceStep = WorkspaceStep::query()
            ->where('app_id', $model->id)
            ->where('phase', $this->phase())
            ->whereKey($step)
            ->first();

        if (! $workspaceStep instanceof WorkspaceStep) {
            throw new GatewayApiException(
                ucfirst($this->phase()->value)." step '{$step}' not found for app '{$model->name}' in phase '{$this->phase()->value}'.",
                'workspace.step_not_found',
                [
                    'step_id' => $step,
                    'app' => $model->name,
                    'phase' => $this->phase()->value,
                ],
            );
        }

        $removed = $payload->forStep($workspaceStep);
        $workspaceStep->deleteAndCompact();
        $remaining = WorkspaceStep::query()
            ->where('app_id', $model->id)
            ->where('phase', $this->phase())
            ->count();

        return [
            'result' => ['action' => 'removed'],
            'step' => $removed,
            'meta' => [
                'remaining_step_count' => $remaining,
                'new_step_count' => $remaining,
            ],
        ];
    }

    private function resolveStepId(): ?int
    {
        $value = $this->option('step');

        if (($value === null || $value === '') && $this->isInteractiveInput()) {
            $value = text(label: 'Step ID', required: true);
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function hasDestructiveConsent(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->isInteractiveInput()) {
            return false;
        }

        return confirm(label: 'Remove this workspace step?', default: false);
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
     * @param  array<string, mixed>  $meta
     */
    private function renderHuman(array $step, array $meta): void
    {
        $this->line("Removed {$this->phaseLabel()} step {$step['id']} from app '{$step['app']}'.");

        $remaining = $meta['remaining_step_count'] ?? $meta['new_step_count'] ?? null;

        if ($remaining === 0) {
            $this->line("App '{$step['app']}' now has no workspace {$this->phaseLabel()} steps.");

            return;
        }

        $this->line('Remaining steps renumbered.');
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
                'meta' => $response['meta'],
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

        $this->error($this->humanErrorMessage($code, $message, $meta));

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function humanErrorMessage(string $code, string $message, array $meta): string
    {
        return match ($code) {
            'validation_failed' => match ($meta['field'] ?? null) {
                'app' => 'Could not resolve parent app. Pass --app=<slug> or run from inside a registered app or workspace path.',
                'force' => 'This is a destructive operation. Use --force or confirm the prompt.',
                default => 'Step ID must be a positive integer.',
            },
            'workspace.app_not_found' => isset($meta['app']) ? "Could not resolve app: {$meta['app']}" : $message,
            'gateway_unavailable' => "Gateway connection is required to remove {$this->phaseLabel()} steps.",
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
