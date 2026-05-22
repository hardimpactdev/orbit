<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Data\Apps\AppWorkerReadinessResult;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\DisableAppWorkerRequest;
use App\Http\Gateway\Requests\Apps\EnableAppWorkerRequest;
use App\Http\Gateway\Requests\Apps\ShowAppWorkerRequest;
use App\Http\Gateway\Responses\Apps\AppWorkerResponse;
use App\Models\App;
use App\Services\Apps\AppWorkerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:worker
    {action? : Action to perform (show|enable|disable)}
    {app? : App name or hostname}
    {--json : Output JSON}')]
#[Description('Inspect or change FrankenPHP worker mode for an app')]
class AppWorkerCommand extends Command
{
    use PromptsForRegistryEntities;

    public function handle(AppWorkerService $service): int
    {
        $action = $this->stringArgument('action');

        if ($action === null || ! in_array($action, ['show', 'enable', 'disable'], true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Action must be one of: show, enable, disable.',
                meta: ['field' => 'action', 'allowed' => ['show', 'enable', 'disable']],
            );
        }

        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $selector = $this->stringArgument('app');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = $this->promptAppSelector();

            if ($selector instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $selector->errorCode() ?? 'gateway_unavailable',
                    message: $selector->getMessage(),
                    meta: $selector->errorMeta(),
                );
            }
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($executionContext === 'control') {
            return $this->forwardAction($action, $selector);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        return match ($action) {
            'show' => $this->showAction($app),
            'enable' => $this->enableAction($app, $service),
            'disable' => $this->disableAction($app, $service),
        };
    }

    private function promptAppSelector(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleApp(label: 'Select an app');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    private function showAction(App $app): int
    {
        return $this->successCommand($this->workerPayload($app), action: 'show');
    }

    private function enableAction(App $app, AppWorkerService $service): int
    {
        $result = $service->enable($app);

        if (! $result['ready']) {
            /** @var AppWorkerReadinessResult $readiness */
            $readiness = $result['readiness'];

            return $this->failCommand(
                code: $readiness->code ?? 'app.worker_readiness_failed',
                message: $readiness->message ?? "App '{$app->name}' is not ready for worker mode.",
                meta: array_merge([
                    'app' => $app->name,
                    'missing' => $readiness->missing,
                ], $readiness->meta),
            );
        }

        return $this->successCommand(
            data: array_merge($this->workerPayload($result['app']), ['changed' => $result['changed']]),
            action: 'enable',
        );
    }

    private function disableAction(App $app, AppWorkerService $service): int
    {
        $result = $service->disable($app);

        return $this->successCommand(
            data: array_merge($this->workerPayload($result['app']), ['changed' => $result['changed']]),
            action: 'disable',
        );
    }

    private function forwardAction(string $action, string $selector): int
    {
        try {
            /** @var AppWorkerResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send($this->gatewayRequestFor($action, $selector))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to manage worker mode.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to manage worker mode.',
                meta: [],
            );
        }

        return $this->successCommand($dto->data, action: $action);
    }

    private function gatewayRequestFor(string $action, string $selector): ShowAppWorkerRequest|EnableAppWorkerRequest|DisableAppWorkerRequest
    {
        return match ($action) {
            'show' => new ShowAppWorkerRequest($selector),
            'enable' => new EnableAppWorkerRequest($selector),
            'disable' => new DisableAppWorkerRequest($selector),
            default => throw new \InvalidArgumentException("Unsupported action '{$action}'."),
        };
    }

    /**
     * Resolve an app selector to a registry record using the documented
     * "name match wins" rule: an exact app name wins over any hostname,
     * domain, or url match. The hostname/domain/url fallback only runs
     * when no name match exists.
     */
    private function resolveApp(string $selector): ?App
    {
        $nameMatch = App::query()
            ->with('node')
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        $domainMatch = App::query()
            ->with('node')
            ->where('domain', $selector)
            ->first();

        if ($domainMatch instanceof App) {
            return $domainMatch;
        }

        // Hostname-derived URL fallback for callers passing the
        // canonical https://<host> form.
        return App::query()
            ->with('node')
            ->get()
            ->first(fn (App $app): bool => $app->url() === "https://{$selector}");
    }

    /**
     * @return array{
     *     app: string,
     *     worker_enabled: bool,
     *     worker_config: array{workers: string|int, max_requests: int}|null,
     * }
     */
    private function workerPayload(App $app): array
    {
        return [
            'app' => $app->name,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successCommand(array $data, string $action): int
    {
        if (! $this->wantsJson()) {
            $this->renderHuman($data, $action);

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderHuman(array $data, string $action): void
    {
        $name = (string) ($data['app'] ?? '');
        $enabled = (bool) ($data['worker_enabled'] ?? false);
        $changed = $data['changed'] ?? null;
        $state = $enabled ? 'enabled' : 'disabled';

        $line = match ($action) {
            'show' => "App '{$name}' worker mode is {$state}.",
            'enable' => $changed === false
                ? "App '{$name}' worker mode already enabled."
                : "App '{$name}' worker mode enabled.",
            'disable' => $changed === false
                ? "App '{$name}' worker mode already disabled."
                : "App '{$name}' worker mode disabled.",
            default => "App '{$name}' worker mode is {$state}.",
        };

        $this->line($line);

        $config = is_array($data['worker_config'] ?? null) ? $data['worker_config'] : null;

        if ($config !== null) {
            $workers = $config['workers'] ?? null;
            $maxRequests = $config['max_requests'] ?? null;
            $this->line('  workers: '.(is_scalar($workers) ? (string) $workers : 'auto'));
            $this->line('  max_requests: '.(is_scalar($maxRequests) ? (string) $maxRequests : ''));
        }
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }
}
