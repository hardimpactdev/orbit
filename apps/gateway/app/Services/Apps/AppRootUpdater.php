<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\PromptAborted;
use App\Models\AppInstance;
use App\Models\Project;
use App\Services\Support\GatewayActionResult;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

use function Laravel\Prompts\text;

final class AppRootUpdater
{
    use PromptsForRegistryEntities;

    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private ?string $output = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function update(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(app(EnactAppRuntime::class));

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $selector = $this->stringArgument('instance');
        $root = $this->stringArgument('root');

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
            return $this->failValidation('instance', 'Instance is required.');
        }

        if ($root === null && $this->isInteractiveInput()) {
            $root = trim(text(label: 'Document root', required: true));
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        try {
            $selection = app(AppSelectorResolver::class)->requireInstance(
                app(AppSelectorResolver::class)->resolveRequired($selector),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->failCommand(
                code: 'instance.not_found',
                message: "Instance '{$selector}' not found.",
                meta: ['instance' => $selector, ...$exception->meta],
            );
        }

        $app = $selection->app;
        $instance = $selection->instance;

        if (
            ! $instance instanceof AppInstance
            || ! $instance->driver_config instanceof OrbitAppInstanceDriverConfigData
        ) {
            return $this->failCommand(
                code: 'instance.unsupported_driver',
                message: "Instance '{$selector}' does not support an Orbit-managed document root.",
                meta: ['instance' => $selector],
            );
        }

        $normalized = $this->normalizeRoot($app, $instance->driver_config, $root);

        if (is_array($normalized)) {
            return $this->failCommand(
                code: 'instance.invalid_root',
                message: 'The root path resolves outside the instance path.',
                meta: $normalized,
            );
        }

        if (! $this->wantsJson()) {
            return $this->updateRootForHuman($app, $instance, $normalized, $enactAppRuntime);
        }

        $changed = $this->applyRootChange($instance, $normalized);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app, $instance->refresh(), $changed, $warnings);
    }

    private function promptAppSelector(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleApp(label: 'Select an instance');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    private function updateRootForHuman(
        Project $app,
        AppInstance $instance,
        string $normalized,
        EnactAppRuntime $enactAppRuntime,
    ): int {
        $changed = $this->applyRootChange($instance, $normalized);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app, $instance->refresh(), $changed, $warnings);
    }

    private function applyRootChange(AppInstance $instance, string $normalized): bool
    {
        $config = $instance->driver_config;
        assert($config instanceof OrbitAppInstanceDriverConfigData);
        $changed = $config->document_root !== $normalized;
        $instance->driver_config = new OrbitAppInstanceDriverConfigData(
            node_id: $config->node_id,
            node: $config->node,
            path: $config->path,
            document_root: $normalized,
            domain: $config->domain,
        );
        $instance->save();

        return $changed;
    }

    /**
     * @return string|array{field: string, root: string, resolved_path: string, app_path: string}
     */
    private function normalizeRoot(
        Project $app,
        OrbitAppInstanceDriverConfigData $config,
        string $root,
    ): string|array {
        $root = trim(str_replace('\\', '/', $root));
        $appPath = rtrim($config->path ?? $app->path, '/');

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
        return false;
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }

    private function line(string $message): void
    {
        $this->output = $message;
    }

    private function error(string $message): void
    {
        $this->output = $message;
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(
        Project $app,
        AppInstance $instance,
        bool $changed,
        array $warnings,
    ): int {
        $node = app(WorkspacePlacement::class)->nodeForInstance($instance);

        return $this->successPayload(
            [
                'project' => $this->appPayload($app),
                'instance' => app(AppInstancePayloads::class)->instance($instance),
                'result' => [
                    'hostname' => parse_url(
                        (string) app(AppInstancePayloads::class)->placement($instance)['url'],
                        PHP_URL_HOST,
                    ) ?: "{$app->name}.{$instance->name}",
                    'changed' => $changed,
                ],
            ],
            $warnings,
            (string) $node?->name,
            $changed,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successPayload(array $data, array $warnings, string $nodeName, bool $artifactsReenacted): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, root?: string} $instance */
            $instance = is_array($data['instance'] ?? null) ? $data['instance'] : [];
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $changed = (bool) ($result['changed'] ?? false);

            $this->line(
                $changed
                    ? "SUCCESS: Document root for instance '"
                    .($instance['name'] ?? '')
                    ."' updated to '"
                    .($instance['root'] ?? '')
                    ."'."
                    : "SUCCESS: Document root for instance '"
                    .($instance['name'] ?? '')
                    ."' is already '"
                    .($instance['root'] ?? '')
                    ."'.",
            );
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
    private function appPayload(Project $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime' => $app->runtimeKind()->value,
            'runtime_config' => $app->runtimeConfig()->toArray(),
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
