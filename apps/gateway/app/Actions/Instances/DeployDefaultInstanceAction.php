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

    /**
     * Deploys the branch's newest commit, or `$commit` when the branch contains it. The `instance.deploy` operation
     * records its history row; the automatic deployment tick starts that operation only for a changed commit.
     */
    public function execute(Instance $instance, DeploymentSteps $steps, DeploymentRequest $request, ?string $commit = null): DeploymentResult
    {
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $request, $steps, $commit): DeploymentResult {
                $instance->refresh()->loadMissing(['project', 'node']);
                $this->assertAvailable($instance);
                $deploySteps = $this->steps->ordered($instance->project);
                $seconds = DeploymentDeadline::InfrastructureSeconds + array_sum(array_map(static fn ($step): int => $step->timeoutSeconds, $deploySteps));

                return $this->deadline->within($seconds, function () use ($instance, $request, $deploySteps, $steps, $commit): DeploymentResult {
                    $collector = new DeploymentEventCollector;
                    $output = new DeploymentRequest(
                        output: static function (DeploymentEvent $event) use ($request, $collector): void {
                            $collector->output($event);
                            $request->emit($event);
                        },
                        phase: static function (DeploymentProgressPhase $phase, ?string $stepName) use ($request, $collector): void {
                            $collector->phase($phase, $stepName);
                            $request->emitPhase($phase, $stepName);
                        },
                    );
                    // A failed deployment reports the commit it was asked for, or the one it tried.
                    $attempted = $commit;
                    $commands = [];
                    $boundary = DeploymentFailureBoundary::Preparation;
                    try {
                        $prepared = $steps->run(new DeploymentStepName(DeploymentProgressPhase::SourcePreparation), $output, function () use ($instance, $output, $commit, &$attempted): array {
                            // The pending mark is the receipt of route convergence; a lost process cannot strand it.
                            if ($instance->development_projection_pending) {
                                $this->projectRoute($instance);
                            }
                            $target = $this->deployment->target($instance, $commit);
                            $attempted = $target->commit;
                            $this->deployment->checkout($instance, $target->commit, $output);

                            return ['commit' => $target->commit];
                        });
                        $prepared = is_array($prepared) && is_string($prepared['commit'] ?? null) ? $prepared['commit'] : null;
                        if ($prepared === null) {
                            throw new ResourceOperationException('deployment.interrupted', 'The prepared commit is missing.', 500);
                        }
                        $attempted = $prepared;
                        $boundary = DeploymentFailureBoundary::BeforeActivation;
                        foreach ($deploySteps as $step) {
                            $steps->run(new DeploymentStepName(DeploymentProgressPhase::BeforeActivation, $step->name), $output, function () use ($instance, $step, $output, &$commands): null {
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
                        $instance->update(['seed_path' => $instance->checkout_path, 'seed_commit' => $prepared, 'seed_repository' => $instance->checkout_path]);

                        return DeploymentResult::checkedOut($prepared, $commands);
                    } catch (Throwable $exception) {
                        // The checkout stays at the new commit. The seed keeps the last commit that deployed,
                        // so the automatic deployment tick tries the commit again after its backoff.
                        return DeploymentResult::failed(null, null, $boundary, $this->errorCode($exception), $commands, $attempted);
                    }
                });
            });
        } catch (Throwable $exception) {
            return DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $this->errorCode($exception), commit: $commit);
        }
    }

    /**
     * Converges the default's Route while its projection is marked pending, so a crash or a failed converge is repaired
     * without a deployment. A completed projection takes no lock.
     */
    public function repairProjection(Instance $instance): void
    {
        if (! $instance->development_projection_pending) {
            return;
        }
        $this->operations->run([$instance->id], function () use ($instance): void {
            $instance->refresh();
            if ($instance->development_projection_pending) {
                $this->projectRoute($instance);
            }
        });
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
