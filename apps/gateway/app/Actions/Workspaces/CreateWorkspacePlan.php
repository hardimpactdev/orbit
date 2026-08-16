<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\ProgressReporter;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final class CreateWorkspacePlan
{
    private ?WorkspaceProvisionResult $provisionResult = null;

    private ?Workspace $workspace = null;

    /** @var list<array<string, mixed>> */
    private array $warnings = [];

    /** @var array{reachable: bool, status: string} */
    private array $httpProbe = [
        'reachable' => false,
        'status' => 'not_run',
    ];

    /** @var array{code: string, message: string, meta: array<string, mixed>}|null */
    private ?array $failure = null;

    /** @var list<string> */
    private array $completedSteps = [];

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly SetupWorkspace $setupWorkspace,
        private readonly App $app,
        private readonly Node $node,
        private readonly string $name,
        private readonly string $base,
        private readonly ?string $phpVersion,
        private readonly Instance $instance,
    ) {}

    public function title(): string
    {
        return 'Creating Workspace';
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     doneLabel: string,
     *     run: callable(): string,
     * }>
     * @mago-expect lint:halstead
     */
    public function steps(): array
    {
        return [
            [
                'key' => 'provision_workspace_source',
                'label' => 'Creating git worktree',
                'doneLabel' => 'Git worktree created',
                'run' => function (): string {
                    try {
                        $this->createWorkspace->ensureNodeReachable($this->node);
                        $this->provisionResult = $this->createWorkspace->provisionWorkspaceSource(
                            $this->app,
                            $this->node,
                            $this->name,
                            $this->base,
                            $this->instance,
                        );
                    } catch (WorkspaceCreateFailed $exception) {
                        $this->failure = [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'meta' => $exception->meta,
                        ];

                        throw $exception;
                    }

                    return $this->provisionResult->path;
                },
            ],
            [
                'key' => 'apply_workspace_registration',
                'label' => 'Apply workspace registration',
                'doneLabel' => 'Applied workspace registration',
                'run' => function (): string {
                    if (! $this->provisionResult instanceof WorkspaceProvisionResult) {
                        throw new RuntimeException('Workspace source was not provisioned.');
                    }

                    try {
                        $this->workspace = $this->createWorkspace->createIntent(
                            $this->app,
                            $this->instance,
                            $this->phpVersion,
                            $this->provisionResult,
                        );
                        $this->setupWorkspace->prepareWorkspaceState($this->workspace);
                    } catch (Throwable $exception) {
                        report($exception);

                        $this->failure = [
                            'code' => 'workspace.registration_failed',
                            'message' => 'Workspace source was created, but registration failed.',
                            'meta' => [
                                'step' => 'apply_workspace_registration',
                                'node' => $this->node->name,
                                'path' => $this->provisionResult->path,
                                'partial_state' => $this->workspace instanceof Workspace
                                    ? 'workspace_registered'
                                    : 'source_retained',
                                'next_command' =>
                                    "orbit workspace:setup {$this->name} --instance={$this->app->name}.{$this->instance->name} --path="
                                        .escapeshellarg($this->provisionResult->path),
                            ],
                        ];

                        throw $exception;
                    }

                    return $this->workspace->name;
                },
            ],
            [
                'key' => 'register_proxy_routes',
                'label' => 'Register proxy routes',
                'doneLabel' => 'Registered proxy routes',
                'run' => function (): string {
                    $workspace = $this->workspaceOrFail();
                    $routeWarnings = $this->setupWorkspace->registerProxyRoutes($workspace);
                    $this->warnings = array_merge($this->warnings, $routeWarnings);

                    if ($routeWarnings !== []) {
                        return 'skip:'.($routeWarnings[0]['message'] ?? 'Proxy route requires convergence.');
                    }

                    return 'ready';
                },
            ],
            [
                'key' => 'initialize_workspace_environment',
                'label' => 'Initialize workspace environment',
                'doneLabel' => 'Initialized workspace environment',
                'run' => function (): string {
                    $this->setupWorkspace->initializeEnvironment($this->workspaceOrFail());

                    return 'ready';
                },
            ],
            [
                'key' => 'install_workspace_runtime_container',
                'label' => 'Install workspace runtime container',
                'doneLabel' => 'Installed workspace runtime container',
                'run' => function (): string {
                    $warning = $this->setupWorkspace->enactRuntimeContainer($this->workspaceOrFail(), $this->node);

                    if ($warning !== null) {
                        $this->warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return 'ready';
                },
            ],
            [
                'key' => 'run_workspace_setup_steps',
                'label' => 'Run workspace setup steps',
                'doneLabel' => 'Ran workspace setup steps',
                'run' => function (): string {
                    $workspace = $this->workspaceOrFail();
                    $setupResult = $this->setupWorkspace->runSetupSteps($workspace, $this->app, $this->node);

                    if ($setupResult['status'] === 'failed') {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => "Workspace enactment on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                            'meta' => [
                                'step' => 'setup_pipeline',
                                'node' => $this->node->name,
                                'reason' => $setupResult['message'],
                            ],
                        ];

                        throw new RuntimeException($this->failure['message']);
                    }

                    return $setupResult['message'];
                },
            ],
            [
                'key' => 'render_inherited_runtime_units',
                'label' => 'Render inherited runtime units',
                'doneLabel' => 'Rendered inherited runtime units',
                'run' => function (): string {
                    $workspace = $this->workspaceOrFail();
                    $processResult = $this->setupWorkspace->startProcesses($this->app, $workspace, $this->node);

                    if (! $processResult['success']) {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => "Workspace enactment on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                            'meta' => [
                                'step' => 'processes',
                                'node' => $this->node->name,
                                'reason' => $processResult['message'],
                            ],
                        ];

                        throw new RuntimeException($this->failure['message']);
                    }

                    return $processResult['count'] === 0
                        ? 'No inherited runtime units'
                        : implode(', ', $processResult['names']);
                },
            ],
            [
                'key' => 'check_workspace_readiness',
                'label' => 'Check workspace readiness',
                'doneLabel' => 'Checked workspace readiness',
                'run' => function (): string {
                    $workspace = $this->workspaceOrFail();
                    $this->httpProbe = $this->setupWorkspace->probeReadiness($workspace);
                    $this->setupWorkspace->markActive($workspace);

                    if (! $this->httpProbe['reachable']) {
                        $warning = [
                            'code' => 'workspace.http_probe_unhealthy',
                            'family' => null,
                            'message' => "Workspace did not become reachable: {$this->httpProbe['status']}",
                            'next_command' => "orbit workspace:setup {$workspace->name} --instance={$this->app->name}.{$this->instance->name}",
                        ];
                        $this->warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return $this->httpProbe['status'];
                },
            ],
        ];
    }

    public function run(ProgressReporter $reporter): CreateWorkspaceResult
    {
        $steps = $this->steps();

        $reporter->tree($this->title(), array_map(static fn (array $step): array => [
            'key' => $step['key'],
            'label' => $step['label'],
            'doneLabel' => $step['doneLabel'],
        ], $steps));

        foreach ($steps as $step) {
            $reporter->stepStart($step['key']);

            try {
                $message = $step['run']();
            } catch (Throwable $exception) {
                if ($this->failure === null) {
                    report($exception);

                    $this->failure = [
                        'code' => 'workspace.enactment_failed',
                        'message' => "Workspace application on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                        'meta' => [
                            'step' => $step['key'],
                            'node' => $this->node->name,
                            'reason' => 'unexpected_failure',
                        ],
                    ];
                }

                $reporter->stepFail($step['key'], $this->failure['message']);

                return CreateWorkspaceResult::failed($this->failure, $this->completedSteps);
            }

            $this->completedSteps[] = $step['key'];

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, offset: 5));

                continue;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        return CreateWorkspaceResult::success($this->resultData(), $this->completedSteps);
    }

    public function doneFooter(): string
    {
        return "Workspace '{$this->name}' created";
    }

    public function failFooter(): string
    {
        return "Failed to create workspace '{$this->name}'.";
    }

    private function workspaceOrFail(): Workspace
    {
        if (! $this->workspace instanceof Workspace) {
            throw new RuntimeException('Workspace intent was not written.');
        }

        return $this->workspace;
    }

    /** @return array{result: array{action: 'created'}, workspace: array<string, mixed>, meta: array<string, mixed>} */
    private function resultData(): array
    {
        return $this->createWorkspace->result(
            $this->workspaceOrFail(),
            $this->app,
            $this->node,
            $this->base,
            $this->httpProbe,
            $this->warnings,
        );
    }
}
