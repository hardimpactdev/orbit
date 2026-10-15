<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentCommandResult;
use App\Domain\Instances\Deployment\DeploymentDeadline;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentEventCollector;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\DeploymentSteps;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\InlineDeploymentSteps;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStepFailed;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\Instance;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Deploys a development default in its own checkout: fetch, check out the branch's newest commit,
 * and run the Project's development deploy steps there. The default's seed fields record the last
 * commit whose steps all passed; other Instances on the Node start from that commit.
 */
final readonly class DeployDefaultInstanceAction
{
    public function __construct(
        private DevelopmentDeployment $deployment,
        private ProjectDevelopmentDeployStepStore $steps,
        private InstanceEnvironmentOperationLock $operations,
        private CommandDeadline $deadline,
        private InstanceDeploymentRecorder $recorder,
        private DevelopmentRouteProjector $routes,
        private DevelopmentProjectionOperationLock $projections,
    ) {}

    /**
     * The steps a deployment of this default runs: source preparation, then one step for each development deploy step.
     *
     * @return list<DeploymentStepName>
     */
    public function plan(Instance $instance): array
    {
        $instance->refresh()->loadMissing(['project', 'node']);
        $this->assertAvailable($instance);

        return [
            new DeploymentStepName(DeploymentProgressPhase::SourcePreparation),
            ...array_map(
                static fn (DevelopmentDeployStep $step): DeploymentStepName => new DeploymentStepName(DeploymentProgressPhase::BeforeActivation, $step->name),
                $this->steps->ordered($instance->project),
            ),
        ];
    }

    /** Scheduled runs return null for an unchanged commit, without a history row. */
    public function execute(Instance $instance, ?DeploymentRequest $request = null, bool $onlyChanged = false, ?string $triggeredBy = null, ?DeploymentSteps $steps = null): ?DeploymentResult
    {
        $request ??= DeploymentRequest::withoutOutput();
        $steps ??= new InlineDeploymentSteps;
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $request, $onlyChanged, $triggeredBy, $steps): ?DeploymentResult {
                $instance->refresh()->loadMissing(['project', 'node']);
                $this->assertAvailable($instance);
                $deploySteps = $this->steps->ordered($instance->project);
                $seconds = DeploymentDeadline::InfrastructureSeconds + array_sum(array_map(static fn ($step): int => $step->timeoutSeconds, $deploySteps));

                return $this->deadline->within($seconds, function () use ($instance, $request, $onlyChanged, $triggeredBy, $deploySteps, $steps): ?DeploymentResult {
                    $collector = new DeploymentEventCollector;
                    $record = null;
                    $output = new DeploymentRequest(
                        output: static function (DeploymentEvent $event) use ($request, $collector): void {
                            $collector->output($event);
                            $request->emit($event);
                        },
                        cancellation: $request->cancellation,
                        phase: static function (DeploymentProgressPhase $phase, ?string $stepName) use ($request, $collector): void {
                            $collector->phase($phase, $stepName);
                            $request->emitPhase($phase, $stepName);
                        },
                    );
                    $commit = null;
                    $commands = [];
                    $boundary = DeploymentFailureBoundary::Preparation;
                    try {
                        $this->assertNotCancelled($output);
                        $prepared = $steps->run(new DeploymentStepName(DeploymentProgressPhase::SourcePreparation), $output, function () use ($instance, $output, $onlyChanged, $triggeredBy, &$record, &$commit): array {
                            // The pending mark is the receipt of route convergence. Repair before the unchanged
                            // fast path so a lost process cannot strand it; a completed projection skips the lock.
                            if ($instance->development_projection_pending) {
                                $this->projectRoute($instance);
                            }
                            $target = $this->deployment->target($instance);
                            if ($onlyChanged && $instance->seed_commit === $target->commit) {
                                return ['commit' => $target->commit, 'unchanged' => true];
                            }
                            if ($triggeredBy !== null) {
                                $record = $this->recorder->start($instance, $triggeredBy);
                            }
                            // A failed checkout reports the commit it tried.
                            $commit = $target->commit;
                            $this->deployment->checkout($instance, $target->commit, $output);

                            return ['commit' => $target->commit, 'unchanged' => false];
                        });
                        if (is_array($prepared) && ($prepared['unchanged'] ?? false) === true) {
                            return null;
                        }
                        $commit = is_array($prepared) && is_string($prepared['commit'] ?? null) ? $prepared['commit'] : null;
                        if ($commit === null) {
                            throw new ResourceOperationException('deployment.interrupted', 'The prepared commit is missing.', 500);
                        }
                        $boundary = DeploymentFailureBoundary::BeforeActivation;
                        foreach ($deploySteps as $step) {
                            $steps->run(new DeploymentStepName(DeploymentProgressPhase::BeforeActivation, $step->name), $output, function () use ($instance, $step, $output, &$commands): null {
                                $this->assertNotCancelled($output);
                                try {
                                    $commands[] = new DeploymentCommandResult($step->name, $this->deployment->executeStep($instance, $step, $output));
                                } catch (RuntimeConvergenceException $exception) {
                                    if ($exception->result !== null) {
                                        $commands[] = new DeploymentCommandResult($step->name, $exception->result);
                                    }
                                    if ($step->required || $exception->result === null || $exception->errorCode !== 'deployment.step_failed') {
                                        throw $exception;
                                    }
                                    $output->emit(new DeploymentEvent($step->name, DeploymentOutputStream::Stderr, "Best-effort step failed (exit {$exception->result->exitCode}): {$step->name}. Deployment will continue.\n", important: true));

                                    throw new OperationStepFailed($exception->errorCode);
                                }

                                return null;
                            });
                        }
                        $instance->update(['seed_path' => $instance->checkout_path, 'seed_commit' => $commit, 'seed_repository' => $instance->checkout_path]);
                        $result = DeploymentResult::checkedOut($commit, $commands);
                    } catch (Throwable $exception) {
                        // The checkout stays at the new commit. The seed keeps the last commit that deployed,
                        // so the next tick retries the steps.
                        $result = DeploymentResult::failed(null, null, $boundary, $this->errorCode($exception), $commands, $commit);
                        if ($record === null && $triggeredBy !== null) {
                            $record = $this->recorder->start($instance, $triggeredBy);
                        }
                    }
                    if ($record !== null) {
                        $this->recorder->finish($record, $result, $collector->events());
                    }

                    return $result;
                });
            });
        } catch (Throwable $exception) {
            return DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $this->errorCode($exception));
        }
    }

    public function assertAvailable(Instance $instance): void
    {
        if (! $instance->placedOnAppDev() || $instance->name !== 'default'
            || $instance->status !== InstanceState::Active
            || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('deployment_config.unavailable', 'Development deployment is available only for an active default checkout on app-dev.', 409);
        }
    }

    private function projectRoute(Instance $instance): void
    {
        $route = $instance->authoritativeRoute();
        if ($route !== null) {
            $instance->update(['development_projection_pending' => true]);
            $this->projections->run(fn () => $this->routes->converge($instance, $route));
            $instance->update(['development_projection_pending' => false]);
        }
    }

    private function assertNotCancelled(DeploymentRequest $request): void
    {
        if ($request->cancellation->requested()) {
            throw new ProcessCancelledException;
        }
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded') {
            return 'deployment.deadline_exceeded';
        }
        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }
        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }
        if ($exception instanceof RuntimeConvergenceException || $exception instanceof ResourceOperationException) {
            return $exception->errorCode;
        }

        return 'deployment.interrupted';
    }
}
