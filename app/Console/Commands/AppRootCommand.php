<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\EnactAppRuntime;
use App\Models\App;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:root
    {app? : App name or hostname}
    {root? : Document root relative to app path}
    {--json : Output JSON}')]
#[Description('Change the document root for an app')]
class AppRootCommand extends Command
{
    public function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app' || $callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        $selector = $this->stringArgument('app');
        $root = $this->stringArgument('root');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        if ($callerRole === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update app roots.',
                meta: [],
            );
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

        $changed = $app->document_root !== $normalized;
        $app->document_root = $normalized;
        $app->save();
        $app->setRelation('node', $app->node);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app->refresh()->load('node'), $changed, $warnings);
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

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
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

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(App $app, bool $changed, array $warnings): int
    {
        if (! $this->wantsJson()) {
            $this->line("Document root for '{$app->name}' updated to '{$app->document_root}'.");

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => [
                    'app' => $this->appPayload($app),
                    'result' => [
                        'hostname' => parse_url($app->url(), PHP_URL_HOST) ?: $app->name,
                        'changed' => $changed,
                    ],
                ],
                'meta' => [
                    'node' => $app->node?->name,
                    'artifacts_reenacted' => $changed,
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
