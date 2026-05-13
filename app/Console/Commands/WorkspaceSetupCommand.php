<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\SetupWorkspace;
use App\Actions\Workspaces\SetupWorkspaceProgress;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\SetupWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\SetupWorkspaceResponse;
use App\Http\Gateway\WorkspaceSetupGatewayStreamClient;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Support\Cli\RemoteProgressRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

#[Signature('workspace:setup
    {name? : Workspace name}
    {--app= : Parent app name}
    {--path= : Explicit workspace path to adopt}
    {--json : Output as JSON}')]
#[Description('Converge a workspace to a ready-to-develop-in state')]
class WorkspaceSetupCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(
        SetupWorkspace $setupWorkspace,
        SetupWorkspaceProgress $setupProgress,
        WorkspaceSetupGatewayStreamClient $setupStream,
    ): int {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Path must be absolute.',
                meta: ['field' => 'path'],
            );
        }

        if ($callerRole === 'control') {
            return $this->forwardSetup($setupStream);
        }

        return $this->runLocally($setupWorkspace, $setupProgress);
    }

    private function runLocally(SetupWorkspace $setupWorkspace, SetupWorkspaceProgress $setupProgress): int
    {
        try {
            [$workspace, $app, $node, $isAdoption] = $this->resolveWorkspace();
        } catch (RuntimeException $e) {
            return $this->failCommand(
                code: $this->resolveErrorCode($e->getMessage()),
                message: $e->getMessage(),
                meta: $this->resolveErrorMeta($e->getMessage()),
            );
        }

        if (! $this->wantsJson()) {
            return $this->runLocallyForHuman($setupProgress, $workspace, $app, $node, $isAdoption);
        }

        try {
            $result = $setupWorkspace->handle($app, $workspace, $node, $isAdoption);
        } catch (RuntimeException $e) {
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

    private function runLocallyForHuman(
        SetupWorkspaceProgress $setupProgress,
        Workspace $workspace,
        App $app,
        Node $node,
        bool $isAdoption,
    ): int {
        $plan = $setupProgress->for($workspace, $app, $node, $isAdoption);
        $exitCode = $this->runStepTree(
            $plan->title(),
            $plan->steps(),
            doneFooter: $plan->doneFooter(),
            failFooter: $plan->failFooter(),
        );

        if ($exitCode !== self::SUCCESS) {
            $failure = $plan->failure();

            if ($failure !== null) {
                return $this->failCommand($failure['code'], $failure['message'], $failure['meta']);
            }

            return self::FAILURE;
        }

        return $this->successCommand($plan->result());
    }

    private function forwardSetup(WorkspaceSetupGatewayStreamClient $setupStream): int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $path = $this->stringOption('path');

        if (! $this->wantsJson()) {
            return $this->forwardSetupForHuman($setupStream, $name, $app, $path);
        }

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

    private function forwardSetupForHuman(
        WorkspaceSetupGatewayStreamClient $setupStream,
        ?string $name,
        ?string $app,
        ?string $path,
    ): int {
        $renderer = new RemoteProgressRenderer($this->output);
        $completeData = [];
        $errorData = [];
        $footer = 'Done';

        $result = $setupStream->run($name, $app, $path, function (string $event, array $payload) use ($renderer, &$completeData, &$errorData, &$footer): void {
            if ($event === 'tree') {
                $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
                $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
                $renderer->tree($title, $steps);

                return;
            }

            if ($event === 'step') {
                $renderer->step(
                    is_string($payload['key'] ?? null) ? $payload['key'] : '',
                    is_string($payload['status'] ?? null) ? $payload['status'] : '',
                    is_string($payload['message'] ?? null) ? $payload['message'] : null,
                );

                return;
            }

            if ($event === 'complete') {
                $completeData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($completeData['footer'] ?? null)) {
                    $footer = $completeData['footer'];
                }

                return;
            }

            if ($event === 'error') {
                $errorData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($errorData['footer'] ?? null)) {
                    $footer = $errorData['footer'];
                }
            }
        });

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to set up a workspace.',
                meta: $result->errorMeta(),
            );
        }

        $renderer->finish($footer, $result === self::SUCCESS);

        if ($result !== self::SUCCESS) {
            return $this->failCommand(
                code: is_string($errorData['code'] ?? null) ? $errorData['code'] : 'workspace.enactment_failed',
                message: is_string($errorData['message'] ?? null) ? $errorData['message'] : 'Workspace setup failed.',
                meta: is_array($errorData['meta'] ?? null) ? $errorData['meta'] : [],
            );
        }

        $resultData = is_array($completeData['result'] ?? null) ? $completeData['result'] : [];

        if ($resultData === []) {
            return $this->failCommand(
                code: 'gateway_stream_invalid',
                message: 'Gateway stream completed without workspace setup result data.',
                meta: [],
            );
        }

        return $this->successCommand($resultData);
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws RuntimeException
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
     * @throws RuntimeException
     */
    private function resolveByPath(string $path, ?string $appName): array
    {
        $app = $this->resolveApp($appName);

        if (! $app instanceof App) {
            throw new RuntimeException('App not found. Pass --app=<name> explicitly.');
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("Node not found for app '{$app->name}'.");
        }

        $workspaceName = basename($path);

        $existing = Workspace::query()
            ->with('app.node')
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->first();

        if (! $this->pathAllowedForWorkspace($app, $path, $existing)) {
            throw new RuntimeException("Path {$path} is outside the parent app workspace policy.");
        }

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

    private function pathAllowedForWorkspace(App $app, string $path, ?Workspace $workspace): bool
    {
        if ($workspace instanceof Workspace && $workspace->agent_ide !== null && $workspace->agent_ide !== 'none') {
            return true;
        }

        $appPath = rtrim($app->path, '/');

        return str_starts_with($path, "{$appPath}/.worktrees/");
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws RuntimeException
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
            throw new RuntimeException("Workspace '{$name}' not found.");
        }

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new RuntimeException("App not found for workspace '{$name}'.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("Node not found for workspace '{$name}'.");
        }

        return [$workspace, $app, $node, false];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     *
     * @throws RuntimeException
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
                throw new RuntimeException("App not found for workspace '{$workspace->name}'.");
            }

            $node = $app->node;

            if (! $node instanceof Node) {
                throw new RuntimeException("Node not found for workspace '{$workspace->name}'.");
            }

            return [$workspace, $app, $node, false];
        }

        throw new RuntimeException('Not inside a registered workspace. Pass --workspace=<name> or run from a workspace directory.');
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
        if (str_contains($message, 'outside the parent app workspace policy')) {
            return 'workspace.path_outside_policy';
        }

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

        $this->line($this->setupSuccessLine($result));
        $this->line("URL: {$result['url']}");

        if ($result['setup_steps']['count'] > 0) {
            $this->line("Setup steps: {$result['setup_steps']['message']}");
        }

        if ($result['processes']['count'] > 0) {
            $this->line('Processes: '.$this->processMessage($result['processes']));
        }

        if ($warnings !== []) {
            $this->line('Warnings:');

            foreach ($warnings as $warning) {
                $message = $warning['message'] ?? $warning['code'] ?? 'unknown warning';
                $this->line("- {$message}");

                if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                    $this->line('  Retry with: orbit '.$warning['next_command']);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function setupSuccessLine(array $result): string
    {
        $workspace = (string) $result['workspace'];
        $app = (string) $result['app'];
        $node = (string) $result['node'];
        $path = (string) ($result['path'] ?? '');

        return match ($result['action'] ?? 'set_up') {
            'adopted' => "Workspace '{$workspace}' adopted from path '{$path}' on node '{$node}'.",
            'converged' => "Workspace '{$workspace}' is already converged on node '{$node}'. No changes were needed.",
            default => "Workspace '{$workspace}' is set up under app '{$app}' on node '{$node}'.",
        };
    }

    /**
     * @param  array<string, mixed>  $processes
     */
    private function processMessage(array $processes): string
    {
        if (isset($processes['message']) && is_string($processes['message']) && $processes['message'] !== '') {
            return $processes['message'];
        }

        if (! isset($processes['names']) || ! is_array($processes['names'])) {
            return 'No processes';
        }

        $names = array_values(array_filter(
            $processes['names'],
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        if ($names === []) {
            return 'No processes';
        }

        return implode(', ', $names);
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
