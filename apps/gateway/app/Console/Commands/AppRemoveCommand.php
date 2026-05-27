<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\RemoveApp;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Requests\Apps\RemoveAppRequest;
use App\Http\Gateway\Responses\Apps\AppListResponse;
use App\Http\Gateway\Responses\Apps\AppRemoveResponse;
use App\Models\App;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\datatable;

#[Signature('app:remove
    {app? : App name or hostname}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove an app and its owned artifacts')]
class AppRemoveCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(RemoveApp $removeApp): int
    {
        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $selector = $this->stringArgument('app');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = $this->promptForApp($executionContext);

            if (is_int($selector)) {
                return $selector;
            }
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App name is required.');
        }

        if ($executionContext === 'control') {
            $consent = $this->confirmRemoval($selector);

            if (is_int($consent)) {
                return $consent;
            }

            return $this->forwardRemove($selector);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['name' => $selector],
            );
        }

        $consent = $this->confirmRemoval($app->name);

        if (is_int($consent)) {
            return $consent;
        }

        if (! $this->wantsJson()) {
            $result = null;
            $exitCode = $this->runStepTree(
                'Removing App',
                $this->progressSteps(function () use ($removeApp, $app, &$result): array {
                    $result = $removeApp->handle($app);

                    return $result;
                }),
                doneFooter: "App '{$app->name}' removed",
                failFooter: "Failed to remove app '{$app->name}'.",
            );

            if ($exitCode !== self::SUCCESS || ! is_array($result)) {
                return self::FAILURE;
            }

            return $this->successCommand($result);
        }

        $result = $removeApp->handle($app);

        return $this->successCommand($result);
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force')) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('force', 'Use --force to remove this app.');
        }

        if (confirm("Remove app '{$name}' and all owned artifacts? This cannot be undone.", default: false)) {
            return null;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
    }

    private function forwardRemove(string $selector): int
    {
        try {
            /** @var AppRemoveResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new RemoveAppRequest($selector))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to remove an app.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove an app.',
                meta: [],
            );
        }

        return $this->successCommand([
            'app' => is_array($dto->data['app'] ?? null) ? $dto->data['app'] : [],
            'result' => is_array($dto->data['result'] ?? null) ? $dto->data['result'] : ['action' => 'removed'],
            'cleanup' => is_array($dto->data['cleanup'] ?? null) ? $dto->data['cleanup'] : [],
            'warnings' => $dto->warnings,
        ]);
    }

    private function promptForApp(string $executionContext): string|int
    {
        $apps = $this->fetchPromptApps($executionContext);

        if ($apps instanceof GatewayApiException) {
            return $this->failCommand(
                code: $apps->errorCode() ?? 'gateway_unavailable',
                message: $apps->getMessage() !== ''
                    ? $apps->getMessage()
                    : 'Gateway connection is required to list removable apps.',
                meta: $apps->errorMeta(),
            );
        }

        $rows = $this->appPromptRows($apps);

        if ($rows === []) {
            return $this->failCommand(
                code: 'app.not_found',
                message: 'No removable apps are registered.',
                meta: [],
            );
        }

        $selected = datatable(
            headers: ['Host', 'Node', 'Repository'],
            rows: $rows,
            label: 'Select an app to remove',
            hint: 'Press / to search',
        );

        return is_string($selected) && $selected !== ''
            ? $selected
            : $this->failValidation('app', 'App is required.');
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    private function fetchPromptApps(string $executionContext): array|GatewayApiException
    {
        if ($executionContext === 'gateway') {
            return App::query()
                ->with('node')
                ->get()
                ->sort(fn (App $first, App $second): int => [
                    mb_strtolower((string) $first->node?->name),
                    mb_strtolower($first->name),
                ] <=> [
                    mb_strtolower((string) $second->node?->name),
                    mb_strtolower($second->name),
                ])
                ->values()
                ->map(fn (App $app): array => [
                    'name' => $app->name,
                    'node' => $app->node?->name,
                    'url' => $app->url(),
                    'repository' => $app->repository,
                ])
                ->all();
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListAppsRequest)
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to list removable apps.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var AppListResponse $dto */
        return $dto->apps;
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, array<int, string>>
     */
    private function appPromptRows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            $name = is_string($app['name'] ?? null) && $app['name'] !== '' ? $app['name'] : null;

            if ($name === null) {
                continue;
            }

            $rows[$name] = [
                $this->hostFromAppPayload($app, $name),
                is_string($app['node'] ?? null) && $app['node'] !== '' ? $app['node'] : '-',
                is_string($app['repository'] ?? null) && $app['repository'] !== '' ? $app['repository'] : '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function hostFromAppPayload(array $app, string $fallback): string
    {
        $url = is_string($app['url'] ?? null) ? $app['url'] : null;

        if ($url !== null) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        $domain = is_string($app['domain'] ?? null) ? $app['domain'] : null;

        return $domain !== null && $domain !== '' ? $domain : $fallback;
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with(['node', 'processes'])
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values()
            ->first();
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
     * @return list<array{key: string, label: string, doneLabel: string, run: callable}>
     */
    private function progressSteps(callable $remove): array
    {
        return [
            [
                'key' => 'validate_removal',
                'label' => 'Validate removal',
                'doneLabel' => 'Validated removal',
                'run' => fn (): string => 'ready',
            ],
            [
                'key' => 'remove_app',
                'label' => 'Apply and verify app removal',
                'doneLabel' => 'Applied and verified app removal',
                'run' => function () use ($remove): string {
                    $remove();

                    return 'removed';
                },
            ],
            [
                'key' => 'remove_proxy_routes',
                'label' => 'Remove app-owned proxy routes',
                'doneLabel' => 'Removed app-owned proxy routes',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'remove_schedules',
                'label' => 'Remove app-owned schedules',
                'doneLabel' => 'Removed app-owned schedules',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'remove_workspaces',
                'label' => 'Remove app-owned workspaces',
                'doneLabel' => 'Removed app-owned workspaces',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'remove_processes',
                'label' => 'Stop and remove app processes',
                'doneLabel' => 'Stopped and removed app processes',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'clean_runtime',
                'label' => 'Clean node-side runtime artifacts',
                'doneLabel' => 'Cleaned node-side runtime artifacts',
                'run' => fn (): string => 'done',
            ],
        ];
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     result: array{action: string},
     *     cleanup: array<string, mixed>,
     *     warnings: list<array<string, string>>
     * }  $result
     */
    private function successCommand(array $result): int
    {
        $data = [
            'app' => $result['app'],
            'result' => $result['result'],
            'cleanup' => $result['cleanup'],
        ];
        $warnings = $result['warnings'];

        if (! $this->wantsJson()) {
            $app = $result['app'];
            $name = (string) ($app['name'] ?? '');

            $this->line("App '{$name}' removed");

            if ($warnings !== []) {
                $this->line('  Drift detected:');

                foreach ($warnings as $warning) {
                    $family = (string) ($warning['family'] ?? 'app');
                    $message = (string) ($warning['message'] ?? $warning['code'] ?? 'cleanup drift');
                    $next = (string) ($warning['next_command'] ?? 'doctor --family='.$family.' --restore');

                    $this->line("  - {$family}: {$message} (run `{$next}`)");
                }
            }

            return self::SUCCESS;
        }

        $payload = [
            'success' => [
                'data' => $data,
            ],
        ];

        if ($warnings !== []) {
            $payload['success']['meta'] = [
                'warnings' => $warnings,
            ];
        }

        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
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
                'meta' => $meta,
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
