<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\SetupWorkspace;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\SetupWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\SetupWorkspaceResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
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
        } catch (RuntimeException $e) {
            return $this->failCommand(
                code: $this->resolveErrorCode($e->getMessage()),
                message: $e->getMessage(),
                meta: $this->resolveErrorMeta($e->getMessage()),
            );
        }

        if (! $this->wantsJson()) {
            return $this->runLocallyForHuman($setupWorkspace, $workspace, $app, $node, $isAdoption);
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
        SetupWorkspace $setupWorkspace,
        Workspace $workspace,
        App $app,
        Node $node,
        bool $isAdoption,
    ): int {
        $wasAlreadyActive = $workspace->lifecycle_status === WorkspaceLifecycleStatus::Active;
        /** @var list<array<string, string>> $warnings */
        $warnings = [];
        /** @var array{status: string, message: string, count: int} $setupResult */
        $setupResult = [
            'status' => 'skipped',
            'message' => 'No setup steps configured',
            'count' => 0,
        ];
        /** @var array{success: bool, message: string, count: int, names: list<string>} $processResult */
        $processResult = [
            'success' => true,
            'message' => 'No processes',
            'count' => 0,
            'names' => [],
        ];
        /** @var array{reachable: bool, status: string} $httpProbe */
        $httpProbe = [
            'reachable' => false,
            'status' => 'not_run',
        ];
        /** @var array{code: string, message: string, meta: array<string, mixed>}|null $failure */
        $failure = null;

        $steps = [
            [
                'key' => 'apply_workspace_registration',
                'label' => 'Apply and verify workspace registration',
                'doneLabel' => 'Applied and verified workspace registration',
                'run' => function () use ($setupWorkspace, $workspace): string {
                    $setupWorkspace->prepareWorkspaceState($workspace);

                    return $workspace->name;
                },
            ],
            [
                'key' => 'register_proxy_routes',
                'label' => 'Register proxy routes',
                'doneLabel' => 'Registered proxy routes',
                'run' => function () use ($setupWorkspace, $workspace, &$warnings): string {
                    $routeWarnings = $setupWorkspace->registerProxyRoutes($workspace);
                    $warnings = array_merge($warnings, $routeWarnings);

                    if ($routeWarnings !== []) {
                        return 'skip:'.(string) ($routeWarnings[0]['message'] ?? 'Proxy route requires convergence.');
                    }

                    return 'ready';
                },
            ],
            [
                'key' => 'install_php_fpm_artifacts',
                'label' => 'Install PHP-FPM artifacts',
                'doneLabel' => 'Installed PHP-FPM artifacts',
                'run' => function () use ($setupWorkspace, $workspace, $node, &$warnings): string {
                    $warning = $setupWorkspace->enactFpmPool($workspace, $node);

                    if ($warning !== null) {
                        $warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return 'ready';
                },
            ],
        ];

        if ($this->hasSetupSteps($app)) {
            $steps[] = [
                'key' => 'run_workspace_setup_steps',
                'label' => 'Run workspace setup steps',
                'doneLabel' => 'Ran workspace setup steps',
                'run' => function () use ($setupWorkspace, $workspace, $app, $node, &$setupResult, &$failure): string {
                    $setupResult = $setupWorkspace->runSetupSteps($workspace, $app, $node);

                    if ($setupResult['status'] === 'failed') {
                        $failure = [
                            'code' => 'workspace.setup_step_failed',
                            'message' => $setupResult['message'],
                            'meta' => [
                                'phase' => 'setup_steps',
                                'node' => $node->name,
                                'path' => $workspace->path,
                            ],
                        ];

                        throw new RuntimeException($setupResult['message']);
                    }

                    return $setupResult['message'];
                },
            ];
        }

        if ($this->hasProcesses($app)) {
            $steps[] = [
                'key' => 'render_inherited_runtime_units',
                'label' => 'Render inherited runtime units',
                'doneLabel' => 'Rendered inherited runtime units',
                'run' => function () use ($setupWorkspace, $workspace, $app, $node, &$processResult, &$failure): string {
                    $processResult = $setupWorkspace->startProcesses($app, $workspace, $node);

                    if (! $processResult['success']) {
                        $failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => $processResult['message'],
                            'meta' => [
                                'phase' => 'process',
                                'node' => $node->name,
                            ],
                        ];

                        throw new RuntimeException($processResult['message']);
                    }

                    return $processResult['message'];
                },
            ];
        }

        $steps[] = [
            'key' => 'check_workspace_readiness',
            'label' => 'Check workspace readiness',
            'doneLabel' => 'Checked workspace readiness',
            'run' => function () use ($setupWorkspace, $workspace, &$warnings, &$httpProbe): string {
                $httpProbe = $setupWorkspace->probeReadiness($workspace);
                $setupWorkspace->markActive($workspace);

                if (! $httpProbe['reachable']) {
                    $warning = [
                        'code' => 'workspace.http_probe_unhealthy',
                        'family' => 'workspace',
                        'message' => "Workspace did not become reachable: {$httpProbe['status']}",
                        'next_command' => 'doctor --fix --family=workspace --restore',
                    ];
                    $warnings[] = $warning;

                    return 'skip:'.$warning['message'];
                }

                return $httpProbe['status'];
            },
        ];

        $action = $this->setupAction($isAdoption, $wasAlreadyActive);
        $exitCode = $this->runStepTree(
            'Setting Up Workspace',
            $steps,
            doneFooter: $this->setupDoneFooter($workspace->name, $action),
            failFooter: "Failed to set up workspace '{$workspace->name}'.",
        );

        if ($exitCode !== self::SUCCESS) {
            if ($failure !== null) {
                return $this->failCommand($failure['code'], $failure['message'], $failure['meta']);
            }

            return self::FAILURE;
        }

        return $this->successCommand([
            'app' => $app->name,
            'workspace' => $workspace->name,
            'node' => $node->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'action' => $action,
            'warnings' => $warnings,
            'setup_steps' => $setupResult,
            'processes' => [
                'status' => 'started',
                'count' => $processResult['count'],
                'names' => $processResult['names'],
                'message' => $processResult['message'],
            ],
            'http_probe' => $httpProbe,
        ]);
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

    private function hasSetupSteps(App $app): bool
    {
        return WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->exists();
    }

    private function hasProcesses(App $app): bool
    {
        return Process::query()
            ->where('app_id', $app->id)
            ->exists();
    }

    /**
     * @return 'set_up'|'adopted'|'converged'
     */
    private function setupAction(bool $isAdoption, bool $wasAlreadyActive): string
    {
        if ($isAdoption) {
            return 'adopted';
        }

        if ($wasAlreadyActive) {
            return 'converged';
        }

        return 'set_up';
    }

    private function setupDoneFooter(string $workspace, string $action): string
    {
        return match ($action) {
            'adopted' => "Workspace '{$workspace}' adopted",
            'converged' => "Workspace '{$workspace}' converged",
            default => "Workspace '{$workspace}' set up",
        };
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
