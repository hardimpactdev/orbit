<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\SetupWorkspace;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\WorkspaceCreateFailed;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\CreateWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\CreateWorkspaceResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('workspace:new
    {name? : Workspace name}
    {--app= : Parent app name}
    {--base=main : Base git ref}
    {--php-version= : PHP version override}
    {--json : Output as JSON}')]
#[Description('Create a new workspace intent')]
class WorkspaceNewCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(CreateWorkspace $createWorkspace, SetupWorkspace $setupWorkspace): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

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

        $name = $this->resolveName();

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name is required.',
                meta: ['field' => 'name'],
            );
        }

        if ($name === 'main') {
            return $this->failCommand(
                code: 'validation_failed',
                message: "The workspace name 'main' is reserved.",
                meta: ['field' => 'name'],
            );
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).',
                meta: ['field' => 'name'],
            );
        }

        if (strlen($name) > 63) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name must not exceed 63 characters.',
                meta: ['field' => 'name'],
            );
        }

        $app = $this->resolveApp($callerRole);

        if ($app === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Parent app is required. Pass --app= or run from an app directory.',
                meta: ['field' => 'app'],
            );
        }

        $base = $this->stringOption('base') ?? 'main';
        $phpVersion = $this->stringOption('php-version');

        if ($callerRole === 'control') {
            return $this->forwardCreate($name, $app, $base, $phpVersion);
        }

        return $this->createLocally($createWorkspace, $setupWorkspace, $name, $app, $base, $phpVersion);
    }

    private function createLocally(
        CreateWorkspace $createWorkspace,
        SetupWorkspace $setupWorkspace,
        string $name,
        string $app,
        string $base,
        ?string $phpVersion,
    ): int {
        $appModel = App::query()
            ->with('node')
            ->where('name', $app)
            ->first();

        if (! $appModel instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
            );
        }

        try {
            $createWorkspace->ensureSupportedPhpVersion($phpVersion);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        $existing = Workspace::query()
            ->where('app_id', $appModel->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Workspace) {
            return $this->failCommand(
                code: 'workspace.already_exists',
                message: "Workspace '{$name}' already exists for app '{$app}'.",
                meta: ['name' => $name, 'app' => $app],
            );
        }

        if (! $this->wantsJson()) {
            return $this->createLocallyForHuman($createWorkspace, $setupWorkspace, $appModel, $name, $base, $phpVersion);
        }

        try {
            $result = $createWorkspace->handle($appModel, $name, $base, $phpVersion);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        return $this->respondSuccess($result);
    }

    private function createLocallyForHuman(
        CreateWorkspace $createWorkspace,
        SetupWorkspace $setupWorkspace,
        App $app,
        string $name,
        string $base,
        ?string $phpVersion,
    ): int {
        $workspace = null;
        /** @var list<array<string, string>> $warnings */
        $warnings = [];
        /** @var array{reachable: bool, status: string} $httpProbe */
        $httpProbe = ['reachable' => false, 'status' => 'not_run'];
        /** @var WorkspaceCreateFailed|null $failure */
        $failure = null;
        $worktreeProvisioned = false;

        try {
            $node = $createWorkspace->resolveAppNode($app);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        $exitCode = $this->runStepTree(
            'Creating Workspace',
            [
                [
                    'key' => 'provision_worktree',
                    'label' => "Provision worktree on {$node->name}",
                    'doneLabel' => "Provisioned worktree on {$node->name}",
                    'run' => function () use ($createWorkspace, $app, $node, $name, $base, $phpVersion, &$workspace, &$warnings, &$failure, &$worktreeProvisioned): string {
                        try {
                            $createWorkspace->ensureNodeReachable($node);
                            $workspace = $createWorkspace->createIntent($app, $name, $phpVersion);
                            $warning = $createWorkspace->provisionWorktree($app, $workspace, $node, $base);
                        } catch (WorkspaceCreateFailed $exception) {
                            $failure = $exception;

                            throw $exception;
                        }

                        if ($warning !== null) {
                            $warnings[] = $warning;

                            return 'skip:'.$warning['message'];
                        }

                        $worktreeProvisioned = true;

                        return $workspace->path;
                    },
                ],
                [
                    'key' => 'register_proxy_routes',
                    'label' => 'Register proxy routes',
                    'doneLabel' => 'Registered proxy routes',
                    'run' => function () use ($setupWorkspace, &$workspace, &$warnings, &$worktreeProvisioned): string {
                        if (! $workspace instanceof Workspace || ! $worktreeProvisioned) {
                            return 'skip:Workspace worktree was not provisioned.';
                        }

                        $setupWorkspace->prepareWorkspaceState($workspace);
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
                    'run' => function () use ($setupWorkspace, $node, &$workspace, &$warnings, &$worktreeProvisioned): string {
                        if (! $workspace instanceof Workspace || ! $worktreeProvisioned) {
                            return 'skip:Workspace worktree was not provisioned.';
                        }

                        $warning = $setupWorkspace->enactFpmPool($workspace, $node);

                        if ($warning !== null) {
                            $warnings[] = $warning;

                            return 'skip:'.$warning['message'];
                        }

                        return 'ready';
                    },
                ],
                [
                    'key' => 'run_workspace_setup_steps',
                    'label' => 'Run workspace setup steps',
                    'doneLabel' => 'Ran workspace setup steps',
                    'run' => function () use ($setupWorkspace, $app, $node, &$workspace, &$failure, &$worktreeProvisioned): string {
                        if (! $workspace instanceof Workspace || ! $worktreeProvisioned) {
                            return 'skip:Workspace worktree was not provisioned.';
                        }

                        $setupResult = $setupWorkspace->runSetupSteps($workspace, $app, $node);

                        if ($setupResult['status'] === 'failed') {
                            $failure = new WorkspaceCreateFailed(
                                'workspace.enactment_failed',
                                "Workspace enactment on node '{$node->name}' stopped before Orbit could classify remaining drift.",
                                [
                                    'step' => 'setup_pipeline',
                                    'node' => $node->name,
                                    'reason' => $setupResult['message'],
                                ],
                            );

                            throw $failure;
                        }

                        return $setupResult['message'];
                    },
                ],
                [
                    'key' => 'render_inherited_runtime_units',
                    'label' => 'Render inherited runtime units',
                    'doneLabel' => 'Rendered inherited runtime units',
                    'run' => function () use ($setupWorkspace, $app, $node, &$workspace, &$failure, &$worktreeProvisioned): string {
                        if (! $workspace instanceof Workspace || ! $worktreeProvisioned) {
                            return 'skip:Workspace worktree was not provisioned.';
                        }

                        $processResult = $setupWorkspace->startProcesses($app, $workspace, $node);

                        if (! $processResult['success']) {
                            $failure = new WorkspaceCreateFailed(
                                'workspace.enactment_failed',
                                "Workspace enactment on node '{$node->name}' stopped before Orbit could classify remaining drift.",
                                [
                                    'step' => 'processes',
                                    'node' => $node->name,
                                    'reason' => $processResult['message'],
                                ],
                            );

                            throw $failure;
                        }

                        if ($processResult['count'] === 0) {
                            return 'No inherited runtime units';
                        }

                        return implode(', ', $processResult['names']);
                    },
                ],
                [
                    'key' => 'check_workspace_readiness',
                    'label' => 'Check workspace readiness',
                    'doneLabel' => 'Checked workspace readiness',
                    'run' => function () use ($setupWorkspace, &$workspace, &$warnings, &$httpProbe, &$worktreeProvisioned): string {
                        if (! $workspace instanceof Workspace || ! $worktreeProvisioned) {
                            return 'skip:Workspace worktree was not provisioned.';
                        }

                        $httpProbe = $setupWorkspace->probeReadiness($workspace);

                        if (! $httpProbe['reachable']) {
                            $warning = [
                                'code' => 'workspace.http_probe_unhealthy',
                                'family' => 'workspace',
                                'message' => "Workspace did not become reachable: {$httpProbe['status']}",
                                'next_command' => 'doctor --fix --family=workspace --restore',
                            ];
                            $warnings[] = $warning;
                            $setupWorkspace->markActive($workspace);

                            return 'skip:'.$warning['message'];
                        }

                        $setupWorkspace->markActive($workspace);

                        return (string) $httpProbe['status'];
                    },
                ],
            ],
            doneFooter: "Workspace '{$name}' created",
            failFooter: "Failed to create workspace '{$name}'.",
        );

        if ($exitCode !== self::SUCCESS || $failure instanceof WorkspaceCreateFailed) {
            if ($failure instanceof WorkspaceCreateFailed) {
                return $this->failCommand(
                    code: $failure->errorCode,
                    message: $failure->getMessage(),
                    meta: $failure->meta,
                );
            }

            return self::FAILURE;
        }

        if (! $workspace instanceof Workspace) {
            return $this->failCommand(
                code: 'workspace.enactment_failed',
                message: "Workspace '{$name}' was not created.",
                meta: ['step' => 'create_intent'],
            );
        }

        return $this->respondSuccess($createWorkspace->result($workspace, $app, $node, $base, $httpProbe, $warnings));
    }

    private function forwardCreate(string $name, string $app, string $base, ?string $phpVersion): int
    {
        try {
            /** @var CreateWorkspaceResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateWorkspaceRequest($name, $app, $base, $phpVersion))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to create a workspace.',
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to create a workspace.',
                meta: [],
            );
        }

        return $this->respondSuccess([
            'result' => ['action' => $dto->action],
            'workspace' => [
                'name' => $dto->name,
                'app' => $dto->app,
                'node' => $dto->node,
                'path' => $dto->path,
                'url' => $dto->url,
                'php_version' => $dto->phpVersion,
                'php_inherited' => $dto->phpInherited,
                'adopted' => $dto->adopted,
                'lifecycle_status' => $dto->lifecycleStatus,
            ],
            'meta' => [
                'node' => $dto->node,
                'base' => $dto->base,
                'http_probe' => $dto->httpProbe,
                'warnings' => $dto->warnings,
            ],
        ]);
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(
                label: 'Workspace name',
                required: true,
                validate: fn (string $value): ?string => $this->validatePromptName($value),
            ));
        }

        return null;
    }

    private function validatePromptName(string $value): ?string
    {
        $name = trim($value);

        if ($name === '') {
            return 'Workspace name is required.';
        }

        if ($name === 'main') {
            return "The workspace name 'main' is reserved.";
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'Workspace name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$';
        }

        if (strlen($name) > 63) {
            return 'Workspace name must not exceed 63 characters.';
        }

        return null;
    }

    private function resolveApp(string $callerRole): ?string
    {
        $app = $this->stringOption('app');

        if ($app !== null) {
            return $app;
        }

        if ($callerRole !== 'gateway') {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        $apps = App::query()
            ->with('node')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        if ($apps->isEmpty()) {
            return null;
        }

        return select(
            label: 'Which app owns this workspace?',
            options: $apps->mapWithKeys(fn (App $app): array => [
                $app->name => "{$app->name} (".($app->node->name ?? 'unknown').')',
            ])->all(),
            required: true,
        );
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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function respondSuccess(array $data): int
    {
        $workspace = $data['workspace'];
        $meta = $data['meta'];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'result' => $data['result'],
                        'workspace' => $workspace,
                    ],
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("Workspace '{$workspace['name']}' created on app '{$workspace['app']}' (node '{$workspace['node']}').");
        $this->line("URL: {$workspace['url']}");

        if ($workspace['php_version'] !== null) {
            $suffix = ($workspace['php_inherited'] ?? false) === true ? ' (inherited from app)' : '';
            $this->line("PHP: {$workspace['php_version']}{$suffix}");
        }

        if (($meta['warnings'] ?? []) !== []) {
            $this->line('Warnings:');

            foreach ($meta['warnings'] as $warning) {
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
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode([
                'error' => $error,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
