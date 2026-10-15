<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentCommandResult;
use App\Domain\Instances\Deployment\DeploymentConfig;
use App\Domain\Instances\Deployment\DeploymentDeadline;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\DeploymentSteps;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\Instance;
use App\Models\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Deploys an Instance as named steps (docs/reference/deployments.md#the-deploy-operation). The steps run through
 * {@see DeploymentSteps}, so an operation that resumes after a lost worker skips the steps that already succeeded and
 * reuses the release they prepared. A development default deploys through {@see DeployDefaultInstanceAction}.
 */
final readonly class DeployInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $configs,
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentSynchronizer $environment,
        private ProductionDeployment $deployment,
        private ProductionPhpRuntimeManager $runtime,
        private InstantiateProjectRuntimeDefinitionsAction $definitions,
        private CommandDeadline $deadline,
        private ?DeployDefaultInstanceAction $development = null,
        private ?RestartProcessAction $restartProcess = null,
    ) {}

    /**
     * The steps a deployment of this Instance runs, in order.
     *
     * @return list<DeploymentStepName>
     *
     * @throws ResourceOperationException `deployment_config.unavailable` when the Instance cannot be deployed
     */
    public function plan(Instance $instance): array
    {
        $instance->refresh();

        if ($instance->placedOnAppDev()) {
            return $this->developmentAction()->plan($instance);
        }

        $config = $this->configs->resolve($instance);
        $plan = [
            new DeploymentStepName(DeploymentProgressPhase::SourcePreparation),
            new DeploymentStepName(DeploymentProgressPhase::EnvironmentSync),
            ...$this->stepNames($config, DeploymentPhase::BeforeActivation),
            new DeploymentStepName(DeploymentProgressPhase::Activation),
        ];

        if (is_string($instance->selected_php_version)) {
            $plan[] = new DeploymentStepName(DeploymentProgressPhase::PhpRefresh);
        }

        return [
            ...$plan,
            new DeploymentStepName(DeploymentProgressPhase::ProcessRestart),
            ...$this->stepNames($config, DeploymentPhase::AfterActivation),
            new DeploymentStepName(DeploymentProgressPhase::Cleanup),
        ];
    }

    /** Runs the deployment's steps through `$steps`: the steps of an `instance.deploy` operation. */
    public function execute(Instance $instance, DeploymentSteps $steps, ?DeploymentRequest $request = null): DeploymentResult
    {
        $request ??= DeploymentRequest::withoutOutput();
        $instance->refresh();
        if ($instance->placedOnAppDev()) {
            $result = $this->developmentAction()->execute($instance, $request, steps: $steps);
            assert($result instanceof DeploymentResult);

            return $result;
        }

        try {
            $config = $this->configs->resolve($instance->refresh());
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                null,
                null,
                DeploymentFailureBoundary::Operation,
                $this->errorCode($exception),
            );
        }

        try {
            return $this->operations->run(
                [$instance->id],
                fn (): DeploymentResult => $this->deadline->within(DeploymentDeadline::for($config)->seconds, fn (): DeploymentResult => $this->deploy($instance->refresh(), $config, $request, $steps)),
            );
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                null,
                null,
                DeploymentFailureBoundary::Operation,
                $this->errorCode($exception),
            );
        }
    }

    /** Reads a release that a step stored as its value. */
    public static function storedRelease(mixed $value): ?DeploymentRelease
    {
        if (! is_array($value) || ! is_string($value['name'] ?? null) || ! is_string($value['path'] ?? null) || ! is_string($value['commit'] ?? null)) {
            return null;
        }

        return new DeploymentRelease($value['name'], $value['path'], $value['commit']);
    }

    /** @return array{name: string, path: string, commit: string}|null */
    public static function storableRelease(?DeploymentRelease $release): ?array
    {
        return $release === null ? null : ['name' => $release->name, 'path' => $release->path, 'commit' => $release->commit];
    }

    private function deploy(
        Instance $instance,
        DeploymentConfig $config,
        DeploymentRequest $request,
        DeploymentSteps $steps,
    ): DeploymentResult {
        $boundary = DeploymentFailureBoundary::Preparation;
        $release = null;
        $selected = null;
        $commands = [];

        try {
            $prepared = $steps->run(new DeploymentStepName(DeploymentProgressPhase::SourcePreparation), $request, function () use ($instance, $config, &$selected): array {
                // A failed preparation reports the selection it found.
                $selected = $this->deployment->selected($instance);

                return [
                    'previous' => self::storableRelease($selected),
                    'release' => self::storableRelease($this->deployment->prepare($instance, $config->branch)),
                ];
            });
            $previous = self::storedRelease(is_array($prepared) ? ($prepared['previous'] ?? null) : null);
            $selected = $previous;
            $release = self::storedRelease(is_array($prepared) ? ($prepared['release'] ?? null) : null)
                ?? throw new ResourceOperationException('deployment.interrupted', 'The prepared release is missing.', 500);
            $boundary = DeploymentFailureBoundary::Environment;
            $steps->run(new DeploymentStepName(DeploymentProgressPhase::EnvironmentSync), $request, function () use ($instance): null {
                $this->environment->execute($instance);

                return null;
            });
            $boundary = DeploymentFailureBoundary::BeforeActivation;
            $this->executeSteps($instance, $release, $config, DeploymentPhase::BeforeActivation, $request, $steps, $commands);
            $boundary = DeploymentFailureBoundary::Activation;
            $activated = $steps->run(new DeploymentStepName(DeploymentProgressPhase::Activation), $request, function () use ($instance, $release, &$boundary, &$selected): ?array {
                $selected = $this->deployment->activate($instance, $release);
                $instance->update(['checkout_path' => $selected->path]);
                $boundary = DeploymentFailureBoundary::AfterActivation;
                $this->definitions->installCaptured($instance);

                return self::storableRelease($selected);
            });
            $selected = self::storedRelease($activated) ?? $selected;
            $boundary = DeploymentFailureBoundary::AfterActivation;

            if (is_string($instance->selected_php_version)) {
                $boundary = DeploymentFailureBoundary::CacheRefresh;
                $steps->run(new DeploymentStepName(DeploymentProgressPhase::PhpRefresh), $request, function () use ($instance): null {
                    $this->runtime->converge($instance);
                    $this->runtime->refreshCache($instance);

                    return null;
                });
            }

            $boundary = DeploymentFailureBoundary::AfterActivation;
            $steps->run(new DeploymentStepName(DeploymentProgressPhase::ProcessRestart), $request, function () use ($instance, $request): null {
                $this->restartProcesses($instance, $request);

                return null;
            });
            $this->executeSteps($instance, $release, $config, DeploymentPhase::AfterActivation, $request, $steps, $commands);
            $steps->run(new DeploymentStepName(DeploymentProgressPhase::Cleanup), $request, function () use ($instance, $selected, $previous, $request): null {
                if ($selected instanceof DeploymentRelease) {
                    $this->prune($instance, $selected, $previous, $request);
                }

                return null;
            });

            return DeploymentResult::succeeded($release, $commands);
        } catch (Throwable $exception) {
            $this->appendFailedCommand($commands, $exception);

            if ($boundary === DeploymentFailureBoundary::Activation) {
                $selected = $this->selectionAfterActivationFailure($instance, $selected);
            }

            return DeploymentResult::failed(
                $release,
                $selected,
                $boundary,
                $this->errorCode($exception),
                $commands,
            );
        }
    }

    private function restartProcesses(Instance $instance, DeploymentRequest $request): void
    {
        app(ProcessAdmissionLock::class)->run([$instance->id], function () use ($instance, $request): void {
            $processes = Process::query()
                ->whereIn('owner_type', Instance::morphTypes())
                ->where('owner_id', $instance->id)
                ->where('status', LifecycleStatus::Active)
                ->where('desired_state', DesiredProcessState::Running)
                ->where('restart_on_deploy', true)
                ->orderBy('id')
                ->get();

            foreach ($processes as $process) {
                try {
                    app(ProcessRuntimeLease::class)->run($process, function (Process $fresh) use ($request): void {
                        if ($fresh->status !== LifecycleStatus::Active || $fresh->desired_state !== DesiredProcessState::Running || ! $fresh->restart_on_deploy) {
                            return;
                        }

                        ($this->restartProcess ?? app(RestartProcessAction::class))->execute($fresh);
                        $request->emit(new DeploymentEvent('process_restart', DeploymentOutputStream::Stdout, "Restarted Process {$fresh->id}.\n"));
                    });
                } catch (Throwable $exception) {
                    $request->emit(new DeploymentEvent('process_restart', DeploymentOutputStream::Stderr, "Failed to restart Process {$process->id}: {$this->errorCode($exception)}.\n", important: true));

                    throw $exception;
                }
            }
        });
    }

    /** Pruning is not the deployment. A release that stays is removed by a later deployment. */
    private function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous, DeploymentRequest $request): void
    {
        try {
            $outcome = $this->deployment->prune($instance, $selected, $previous);

            foreach ($outcome['removed'] as $name) {
                $request->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stdout, "Removed old release {$name}.\n"));
            }

            foreach ($outcome['in_use'] as $name) {
                $request->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, "Kept old release {$name}: a running process still uses it. Stop or restart its remaining processes to free it.\n", important: true));
            }
        } catch (Throwable) {
            $request->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, "Release cleanup failed; a later deployment will retry cleanup.\n", important: true));
        }
    }

    private function selectionAfterActivationFailure(
        Instance $instance,
        ?DeploymentRelease $lastKnownSelection,
    ): ?DeploymentRelease {
        try {
            $selected = $this->deployment->selected($instance);
        } catch (Throwable) {
            return $lastKnownSelection;
        }

        if ($selected !== null) {
            try {
                $instance->update(['checkout_path' => $selected->path]);
            } catch (Throwable) {
                return $selected;
            }
        }

        return $selected;
    }

    /** @return list<DeploymentStepName> */
    private function stepNames(DeploymentConfig $config, DeploymentPhase $phase): array
    {
        $names = [];

        foreach ($config->steps as $step) {
            if ($step->phase === $phase) {
                $names[] = new DeploymentStepName(self::progressPhase($phase), $step->name);
            }
        }

        return $names;
    }

    private static function progressPhase(DeploymentPhase $phase): DeploymentProgressPhase
    {
        return $phase === DeploymentPhase::BeforeActivation
            ? DeploymentProgressPhase::BeforeActivation
            : DeploymentProgressPhase::AfterActivation;
    }

    /**
     * @param  list<DeploymentCommandResult>  $commands
     */
    private function executeSteps(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentConfig $config,
        DeploymentPhase $phase,
        DeploymentRequest $request,
        DeploymentSteps $steps,
        array &$commands,
    ): void {
        foreach ($config->steps as $step) {
            if ($step->phase !== $phase) {
                continue;
            }

            $steps->run(new DeploymentStepName(self::progressPhase($phase), $step->name), $request, function () use ($instance, $release, $step, $request, &$commands): null {
                $commands[] = new DeploymentCommandResult(
                    $step->name,
                    $this->deployment->executeStep($instance, $release, $step, $request),
                );

                return null;
            });
        }
    }

    /** @param list<DeploymentCommandResult> $commands */
    private function appendFailedCommand(array &$commands, Throwable $exception): void
    {
        if (! $exception instanceof RuntimeConvergenceException || $exception->result === null) {
            return;
        }

        $name = str_starts_with($exception->step, 'deployment-step-')
            ? substr($exception->step, strlen('deployment-step-'))
            : $exception->step;
        $commands[] = new DeploymentCommandResult($name, $exception->result);
    }

    private function developmentAction(): DeployDefaultInstanceAction
    {
        return $this->development ?? app(DeployDefaultInstanceAction::class);
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded') {
            return 'deployment.deadline_exceeded';
        }

        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException || $exception instanceof ProcessOperationException) {
            return $exception->errorCode;
        }

        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }

        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }

        return 'deployment.interrupted';
    }
}
