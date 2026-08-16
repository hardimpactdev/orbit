<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\ProgressReporter;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final class SetupWorkspacePlan
{
    /** @var list<array<string, mixed>> */
    private array $warnings = [];

    /** @var array{status: string, message: string, count: int} */
    private array $setupResult = [
        'status' => 'skipped',
        'message' => 'No setup steps configured',
        'count' => 0,
    ];

    /** @var array{success: bool, message: string, count: int, names: list<string>} */
    private array $processResult = [
        'success' => true,
        'message' => 'No processes',
        'count' => 0,
        'names' => [],
    ];

    /** @var array{reachable: bool, status: string} */
    private array $httpProbe = [
        'reachable' => false,
        'status' => 'not_run',
    ];

    /** @var array{code: string, message: string, meta: array<string, mixed>}|null */
    private ?array $failure = null;

    /** @var list<string> */
    private array $completedSteps = [];

    private readonly bool $wasAlreadyActive;

    private ?ProgressReporter $reporter = null;

    public function __construct(
        private readonly SetupWorkspace $setupWorkspace,
        private readonly Workspace $workspace,
        private readonly App $app,
        private readonly Node $node,
        private readonly bool $isAdoption,
    ) {
        $this->wasAlreadyActive = $workspace->lifecycle_status === WorkspaceLifecycleStatus::Active;
    }

    public function title(): string
    {
        return 'Setting Up Workspace';
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     doneLabel: string,
     *     phase: string,
     *     run: callable(): string,
     * }>
     * @mago-expect lint:halstead
     */
    public function steps(): array
    {
        $steps = [
            [
                'key' => 'apply_workspace_registration',
                'label' => 'Apply and verify workspace registration',
                'doneLabel' => 'Applied and verified workspace registration',
                'phase' => 'registry',
                'run' => function (): string {
                    $this->setupWorkspace->prepareWorkspaceState($this->workspace);

                    return $this->workspace->name;
                },
            ],
            [
                'key' => 'register_proxy_routes',
                'label' => 'Register proxy routes',
                'doneLabel' => 'Registered proxy routes',
                'phase' => 'routing',
                'run' => function (): string {
                    $routeWarnings = $this->setupWorkspace->registerProxyRoutes($this->workspace);
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
                'phase' => 'artifacts',
                'run' => function (): string {
                    $this->setupWorkspace->initializeEnvironment($this->workspace);

                    return 'ready';
                },
            ],
            [
                'key' => 'install_workspace_runtime_container',
                'label' => 'Install workspace runtime container',
                'doneLabel' => 'Installed workspace runtime container',
                'phase' => 'artifacts',
                'run' => function (): string {
                    $warning = $this->setupWorkspace->enactRuntimeContainer($this->workspace, $this->node);

                    if ($warning !== null) {
                        $this->warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return 'ready';
                },
            ],
        ];

        if ($this->hasSetupSteps()) {
            $steps[] = [
                'key' => 'run_workspace_setup_steps',
                'label' => 'Run workspace setup steps',
                'doneLabel' => 'Ran workspace setup steps',
                'phase' => 'setup_steps',
                'run' => function (): string {
                    $setupResult = $this->setupWorkspace->runSetupSteps(
                        $this->workspace,
                        $this->app,
                        $this->node,
                        $this->reportSetupStepProgress(...),
                    );

                    $this->setupResult = [
                        'status' => $setupResult['status'],
                        'message' => $setupResult['message'],
                        'count' => $setupResult['count'],
                    ];

                    if ($setupResult['status'] === 'failed') {
                        $this->failure = [
                            'code' => 'workspace.setup_step_failed',
                            'message' => $setupResult['message'],
                            'meta' => [
                                'step' => $setupResult['step'] ?? 'unknown',
                                'exit_code' => $setupResult['exit_code'] ?? 1,
                                'phase' => 'setup_steps',
                                'node' => $this->node->name,
                                'path' => $this->workspace->path,
                            ],
                        ];

                        throw new RuntimeException($setupResult['message']);
                    }

                    return $this->setupResult['message'];
                },
            ];
        }

        if ($this->hasProcesses()) {
            $steps[] = [
                'key' => 'render_inherited_runtime_units',
                'label' => 'Render inherited runtime units',
                'doneLabel' => 'Rendered inherited runtime units',
                'phase' => 'processes',
                'run' => function (): string {
                    $this->processResult = $this->setupWorkspace->startProcesses(
                        $this->app,
                        $this->workspace,
                        $this->node,
                    );

                    if (! $this->processResult['success']) {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => $this->processResult['message'],
                            'meta' => [
                                'phase' => 'processes',
                                'node' => $this->node->name,
                            ],
                        ];

                        throw new RuntimeException($this->processResult['message']);
                    }

                    return $this->processResult['message'];
                },
            ];
        }

        $steps[] = [
            'key' => 'check_workspace_readiness',
            'label' => 'Check workspace readiness',
            'doneLabel' => 'Checked workspace readiness',
            'phase' => 'http_probe',
            'run' => function (): string {
                $this->httpProbe = $this->setupWorkspace->probeReadiness($this->workspace);
                $this->setupWorkspace->markActive($this->workspace);

                if (! $this->httpProbe['reachable']) {
                    $warning = [
                        'code' => 'workspace.http_probe_unhealthy',
                        'family' => null,
                        'message' => "Workspace did not become reachable: {$this->httpProbe['status']}",
                        'next_command' => "orbit workspace:setup {$this->workspace->name} --instance={$this->app->name}.{$this->workspace->instance->name}",
                    ];
                    $this->warnings[] = $warning;

                    return 'skip:'.$warning['message'];
                }

                return $this->httpProbe['status'];
            },
        ];

        return $steps;
    }

    public function run(ProgressReporter $reporter): SetupWorkspaceResult
    {
        $this->reporter = $reporter;
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
                        'message' => "Workspace artifact application on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                        'meta' => [
                            'phase' => $step['phase'],
                            'node' => $this->node->name,
                            'reason' => 'unexpected_failure',
                        ],
                    ];
                }

                $reporter->stepFail($step['key'], $this->failure['message']);
                $this->reporter = null;

                return SetupWorkspaceResult::failed($this->failure, $this->completedSteps);
            }

            $this->completedSteps[] = $step['key'];

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, offset: 5));

                continue;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        $this->reporter = null;

        return SetupWorkspaceResult::success($this->resultData(), $this->completedSteps);
    }

    private function reportSetupStepProgress(string $event, WorkspaceStep $step, int $index, int $count): void
    {
        if ($this->reporter === null) {
            return;
        }

        $command = str($step->command)->squish()->limit(80)->toString();
        $message = match ($event) {
            'completed' => "Completed setup step {$index}/{$count}: {$command}",
            'failed' => "Failed setup step {$index}/{$count}: {$command}",
            default => "Running setup step {$index}/{$count}: {$command}",
        };

        $this->reporter->stepProgress('run_workspace_setup_steps', 'progress', $message);
    }

    public function doneFooter(): string
    {
        return "Workspace ready and available at: {$this->workspace->url()}";
    }

    public function failFooter(): string
    {
        return "Failed to set up workspace '{$this->workspace->name}'.";
    }

    /**
     * @return array{
     *     app: string,
     *     instance: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, mixed>>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>, message: string},
     *     http_probe: array{reachable: bool, status: string},
     * }
     */
    private function resultData(): array
    {
        $this->workspace->loadMissing('instance');

        return [
            'app' => $this->app->name,
            'instance' => $this->workspace->instance->name,
            'workspace' => $this->workspace->name,
            'node' => $this->node->name,
            'path' => $this->workspace->path,
            'url' => $this->workspace->url(),
            'action' => $this->action(),
            'warnings' => $this->warnings,
            'setup_steps' => $this->setupResult,
            'processes' => [
                'status' => 'started',
                'count' => $this->processResult['count'],
                'names' => $this->processResult['names'],
                'message' => $this->processResult['message'],
            ],
            'http_probe' => $this->httpProbe,
        ];
    }

    /** @return 'set_up'|'adopted'|'converged' */
    private function action(): string
    {
        if ($this->isAdoption) {
            return 'adopted';
        }

        if ($this->wasAlreadyActive) {
            return 'converged';
        }

        return 'set_up';
    }

    private function hasSetupSteps(): bool
    {
        $this->workspace->loadMissing('instance');

        return $this->setupWorkspace->hasSetupSteps($this->app, $this->workspace);
    }

    private function hasProcesses(): bool
    {
        return $this->setupWorkspace->hasProcesses($this->app, $this->workspace, $this->node);
    }
}
