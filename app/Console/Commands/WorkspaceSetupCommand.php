<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\SetupWorkspace;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\SetupWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\SetupWorkspaceResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('workspace:setup
    {name? : Workspace name}
    {--app= : Parent app name}
    {--path= : Explicit workspace path to adopt}
    {--json : Output as JSON}')]
#[Description('Converge a workspace to a ready-to-develop-in state')]
class WorkspaceSetupCommand extends Command
{
    public function handle(SetupWorkspace $setupWorkspace): int
    {
        $callerRole = $this->callerRole();

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

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Path must be absolute.',
                meta: ['field' => 'path'],
            );
        }

        if ($callerRole === 'control') {
            return $this->forwardSetup();
        }

        if ($callerRole === 'app') {
            return $this->forwardSetup();
        }

        return $this->runLocally($setupWorkspace);
    }

    private function runLocally(SetupWorkspace $setupWorkspace): int
    {
        try {
            [$workspace, $app, $node, $isAdoption] = $this->resolveWorkspace();
        } catch (\RuntimeException $e) {
            return $this->failCommand(
                code: $this->resolveErrorCode($e->getMessage()),
                message: $e->getMessage(),
                meta: $this->resolveErrorMeta($e->getMessage()),
            );
        }

        try {
            $result = $setupWorkspace->handle($app, $workspace, $node, $isAdoption);
        } catch (\RuntimeException $e) {
            return $this->failCommand(
                code: 'workspace.enactment_failed',
                message: $e->getMessage(),
                meta: [
                    'phase' => 'artifacts',
                    'node' => $node->name,
                ],
            );
        }

        return $this->successCommand($result);
    }

    private function forwardSetup(): int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $path = $this->stringOption('path');

        try {
            /** @var SetupWorkspaceResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new SetupWorkspaceRequest(
                    name: $name,
                    app: $app,
                    path: $path,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to set up a workspace.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to set up a workspace.',
                meta: [],
            );
        }

        return $this->successCommand([
            'app' => $dto->app,
            'workspace' => $dto->workspace,
            'node' => $dto->node,
            'url' => $dto->url,
            'action' => $dto->action,
            'warnings' => $dto->warnings,
            'setup_steps' => $dto->setupSteps,
            'processes' => $dto->processes,
            'http_probe' => $dto->httpProbe,
        ]);
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveWorkspace(): array
    {
        $name = $this->stringArgument('name');
        $appName = $this->stringOption('app');
        $path = $this->stringOption('path');

        if ($path !== null) {
            return $this->resolveByPath($path, $appName);
        }

        if ($name !== null) {
            return $this->resolveByName($name, $appName);
        }

        return $this->resolveByCwd();
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveByPath(string $path, ?string $appName): array
    {
        $app = $this->resolveApp($appName);

        if (! $app instanceof App) {
            throw new \RuntimeException('App not found. Pass --app=<name> explicitly.');
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new \RuntimeException("Node not found for app '{$app->name}'.");
        }

        $workspaceName = basename($path);

        $existing = Workspace::query()
            ->with('app.node')
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->first();

        if ($existing instanceof Workspace) {
            $existing->update(['path' => $path]);
            $existing->load(['app', 'app.node']);

            return [$existing, $app, $node, false];
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $workspaceName,
            'path' => $path,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $workspace->load('app.node');

        return [$workspace, $app, $node, true];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveByName(string $name, ?string $appName): array
    {
        $query = Workspace::query()
            ->with(['app.node'])
            ->where('name', $name);

        if ($appName !== null) {
            $query->whereHas('app', fn (Builder $q): Builder => $q->where('name', $appName));
        }

        $workspace = $query->first();

        if (! $workspace instanceof Workspace) {
            throw new \RuntimeException("Workspace '{$name}' not found.");
        }

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new \RuntimeException("App not found for workspace '{$name}'.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new \RuntimeException("Node not found for workspace '{$name}'.");
        }

        return [$workspace, $app, $node, false];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws \RuntimeException
     */
    private function resolveByCwd(): array
    {
        $cwd = realpath((string) getcwd()) ?: (string) getcwd();

        $workspace = Workspace::query()
            ->with('app.node')
            ->get()
            ->first(function (Workspace $w) use ($cwd): bool {
                $workspacePath = realpath($w->path) ?: $w->path;

                return $workspacePath === $cwd || str_starts_with($cwd, "{$workspacePath}/");
            });

        if ($workspace instanceof Workspace) {
            $app = $workspace->app;

            if (! $app instanceof App) {
                throw new \RuntimeException("App not found for workspace '{$workspace->name}'.");
            }

            $node = $app->node;

            if (! $node instanceof Node) {
                throw new \RuntimeException("Node not found for workspace '{$workspace->name}'.");
            }

            return [$workspace, $app, $node, false];
        }

        throw new \RuntimeException('Not inside a registered workspace. Pass --workspace=<name> or run from a workspace directory.');
    }

    private function resolveApp(?string $appName): ?App
    {
        if ($appName === null) {
            return null;
        }

        return App::query()
            ->with('node')
            ->where('name', $appName)
            ->first();
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

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function resolveErrorCode(string $message): string
    {
        if (str_starts_with($message, 'Path ')) {
            return 'validation_failed';
        }

        if (str_contains($message, 'App not found') || str_contains($message, 'Node not found for app')) {
            return 'validation_failed';
        }

        if (str_contains($message, 'not found')) {
            return 'workspace.not_found';
        }

        return 'validation_failed';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveErrorMeta(string $message): array
    {
        if (str_starts_with($message, 'Path ')) {
            return ['field' => 'path'];
        }

        if (str_contains($message, 'App not found') || str_contains($message, 'Node not found for app')) {
            return ['field' => 'app'];
        }

        if (str_contains($message, 'not found')) {
            return ['field' => 'workspace'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function successCommand(array $result): int
    {
        $warnings = $result['warnings'] ?? [];

        if ($this->wantsJson()) {
            $data = [
                'app' => $result['app'],
                'workspace' => $result['workspace'],
                'node' => $result['node'],
                'url' => $result['url'],
                'action' => $result['action'],
                'setup_steps' => $result['setup_steps'],
                'processes' => $result['processes'],
                'http_probe' => $result['http_probe'],
            ];

            $meta = [];

            if ($warnings !== []) {
                $meta['warnings'] = $warnings;
            }

            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("Workspace '{$result['workspace']}' for app '{$result['app']}' is ready.");
        $this->line("  URL: {$result['url']}");
        $this->line("  Action: {$result['action']}");

        if ($result['setup_steps']['count'] > 0) {
            $this->line("  Setup steps: {$result['setup_steps']['message']}");
        }

        if ($result['processes']['count'] > 0) {
            $this->line("  Processes: {$result['processes']['message']}");
        }

        if ($warnings !== []) {
            $this->line('  Warnings:');

            foreach ($warnings as $warning) {
                $message = $warning['message'] ?? $warning['code'] ?? 'unknown warning';
                $this->line("    - {$message}");
            }
        }

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
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
