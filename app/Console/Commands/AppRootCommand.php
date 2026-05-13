<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\UpdateAppRootRequest;
use App\Http\Gateway\Responses\Apps\AppRootUpdateResponse;
use App\Models\App;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\text;

#[Signature('app:root
    {app? : App name or hostname}
    {root? : Document root relative to app path}
    {--json : Output JSON}')]
#[Description('Change the document root for an app')]
class AppRootCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $selector = $this->stringArgument('app');
        $root = $this->stringArgument('root');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = trim(text(label: 'App name or hostname', required: true));
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($root === null && $this->isInteractiveInput()) {
            $root = trim(text(label: 'Document root', required: true));
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        if ($callerRole === 'control') {
            return $this->forwardUpdate($selector, $root);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "Application '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        $normalized = $this->normalizeRoot($app, $root);

        if (is_array($normalized)) {
            return $this->failCommand(
                code: 'app.invalid_root',
                message: 'The root path resolves outside the application path.',
                meta: $normalized,
            );
        }

        if (! $this->wantsJson()) {
            return $this->updateRootForHuman($app, $normalized, $enactAppRuntime);
        }

        $changed = $this->applyRootChange($app, $normalized);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app->refresh()->load('node'), $changed, $warnings);
    }

    private function updateRootForHuman(App $app, string $normalized, EnactAppRuntime $enactAppRuntime): int
    {
        $changed = false;
        $warnings = [];

        $exitCode = $this->runStepTree(
            'Updating App Root',
            [
                [
                    'key' => 'apply_root_change',
                    'label' => 'Apply and verify root change',
                    'doneLabel' => 'Applied and verified root change',
                    'run' => function () use ($app, $normalized, &$changed): string {
                        $changed = $this->applyRootChange($app, $normalized);

                        return $normalized;
                    },
                ],
                [
                    'key' => 'apply_php_fpm',
                    'label' => 'Apply PHP-FPM configuration',
                    'doneLabel' => 'Applied PHP-FPM configuration',
                    'run' => function () use ($app, $enactAppRuntime, &$warnings): string {
                        $warnings = $enactAppRuntime->handle($app);

                        return 'ready';
                    },
                ],
                [
                    'key' => 'apply_routes',
                    'label' => 'Apply proxy routes',
                    'doneLabel' => 'Applied proxy routes',
                    'run' => fn (): string => 'ready',
                ],
            ],
            doneFooter: 'App root updated',
            failFooter: "Failed to update app root for '{$app->name}'.",
        );

        if ($exitCode !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->successCommand($app->refresh()->load('node'), $changed, $warnings);
    }

    private function applyRootChange(App $app, string $normalized): bool
    {
        $changed = $app->document_root !== $normalized;
        $app->document_root = $normalized;
        $app->save();
        $app->setRelation('node', $app->node);

        return $changed;
    }

    private function forwardUpdate(string $selector, string $root): int
    {
        try {
            /** @var AppRootUpdateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new UpdateAppRootRequest(app: $selector, root: $root))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to update app roots.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update app roots.',
                meta: [],
            );
        }

        $app = $dto->data['app'] ?? [];
        $nodeName = is_array($app) && is_string($app['node'] ?? null) ? $app['node'] : '';

        return $this->successPayload($dto->data, $dto->warnings, $nodeName, $dto->artifactsReenacted);
    }

    private function resolveApp(string $selector): ?App
    {
        $apps = App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values();

        if ($apps->count() !== 1) {
            return null;
        }

        return $apps->first();
    }

    /**
     * @return string|array{field: string, root: string, resolved_path: string, app_path: string}
     */
    private function normalizeRoot(App $app, string $root): string|array
    {
        $root = trim(str_replace('\\', '/', $root));
        $appPath = rtrim($app->path, '/');

        if ($root === '' || str_starts_with($root, '/')) {
            return $this->invalidRootMeta($root, $appPath, $root);
        }

        $segments = [];

        foreach (explode('/', $root) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = $segments === [] ? '.' : implode('/', $segments);
        $resolved = $normalized === '.'
            ? $appPath
            : $appPath.'/'.implode('/', $segments);

        if ($root === '..' || str_starts_with($root, '../') || str_contains($root, '/../')) {
            return $this->invalidRootMeta($root, $appPath, $resolved);
        }

        return $normalized;
    }

    /**
     * @return array{field: string, root: string, resolved_path: string, app_path: string}
     */
    private function invalidRootMeta(string $root, string $appPath, string $resolved): array
    {
        return [
            'field' => 'root',
            'root' => $root,
            'resolved_path' => str_starts_with($resolved, '/') ? $resolved : $appPath.'/'.$resolved,
            'app_path' => $appPath,
        ];
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
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(App $app, bool $changed, array $warnings): int
    {
        return $this->successPayload([
            'app' => $this->appPayload($app),
            'result' => [
                'hostname' => parse_url($app->url(), PHP_URL_HOST) ?: $app->name,
                'changed' => $changed,
            ],
        ], $warnings, (string) $app->node?->name, $changed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successPayload(array $data, array $warnings, string $nodeName, bool $artifactsReenacted): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, root?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $changed = (bool) ($result['changed'] ?? false);

            $this->line($changed
                ? "SUCCESS: Document root for app '".(string) ($app['name'] ?? '')."' updated to '".(string) ($app['root'] ?? '')."'."
                : "SUCCESS: Document root for app '".(string) ($app['name'] ?? '')."' is already '".(string) ($app['root'] ?? '')."'.");
            $this->line("Artifacts successfully re-enacted on node '{$nodeName}'.");

            if ($warnings !== []) {
                foreach ($warnings as $warning) {
                    $this->line('WARNING: '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));
                    $this->line('  Code:  '.(string) ($warning['code'] ?? 'warning'));

                    if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                        $this->line('  Next:  orbit '.$warning['next_command']);
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => [
                    'node' => $nodeName,
                    'artifacts_reenacted' => $artifactsReenacted,
                    'warnings' => $warnings,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ];
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
