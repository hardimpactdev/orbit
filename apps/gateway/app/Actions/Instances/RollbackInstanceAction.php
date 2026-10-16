<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentErrorCode;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\DeploymentSteps;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use Throwable;

/**
 * Rolls a production Instance back to a retained release as named steps (docs/reference/deployments.md#roll-back).
 * The steps run through {@see DeploymentSteps}, so an `instance.rollback` operation that resumes after a lost worker
 * skips the steps that already succeeded.
 */
final readonly class RollbackInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $configs,
        private InstanceEnvironmentOperationLock $operations,
        private ProductionDeployment $deployment,
        private ProductionPhpRuntimeManager $runtime,
        private CommandDeadline $deadline,
        private ?RestartInstanceProcessesAction $processes = null,
    ) {}

    /**
     * The steps a rollback of this Instance runs, in order.
     *
     * @return list<DeploymentStepName>
     *
     * @throws ResourceOperationException `deployment_config.unavailable` when the Instance cannot be rolled back
     */
    public function plan(Instance $instance): array
    {
        $this->configs->assertAvailable($instance->refresh());
        $plan = [
            new DeploymentStepName(DeploymentProgressPhase::Rollback),
            new DeploymentStepName(DeploymentProgressPhase::Activation),
        ];

        if (is_string($instance->selected_php_version)) {
            $plan[] = new DeploymentStepName(DeploymentProgressPhase::PhpRefresh);
        }

        return [...$plan, new DeploymentStepName(DeploymentProgressPhase::ProcessRestart)];
    }

    /**
     * Refuses a release that the Instance does not retain, before a rollback records anything or supersedes a deploy. The
     * `rollback` step checks the release again, because a deploy's cleanup can remove it in between.
     *
     * @throws ResourceOperationException `rollback.release_not_retained` (422) for a release that is not retained
     */
    public function assertRetained(Instance $instance, string $releaseName): void
    {
        if (! in_array($releaseName, $this->deployment->releases($instance)->releases, true)) {
            throw new ResourceOperationException(
                'rollback.release_not_retained',
                "The Instance does not retain the release [{$releaseName}]. List its releases with instance:release:list.",
                422,
                details: ['release' => $releaseName],
            );
        }
    }

    /**
     * Runs the rollback's steps through `$steps`: the steps of an `instance.rollback` operation. The `rollback` step
     * finds the retained release, and the `activation` step selects it.
     */
    public function execute(
        Instance $instance,
        string $releaseName,
        DeploymentSteps $steps,
        DeploymentRequest $request,
    ): DeploymentResult {
        try {
            $this->configs->assertAvailable($instance->refresh());

            return $this->operations->run(
                [$instance->id],
                fn (): DeploymentResult => $this->deadline->within(900, fn (): DeploymentResult => $this->rollback($instance->refresh(), $releaseName, $request, $steps)),
            );
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                null,
                null,
                DeploymentFailureBoundary::Operation,
                DeploymentErrorCode::fromException($exception),
            );
        }
    }

    private function rollback(
        Instance $instance,
        string $releaseName,
        DeploymentRequest $request,
        DeploymentSteps $steps,
    ): DeploymentResult {
        $boundary = DeploymentFailureBoundary::RollbackSelection;
        $release = null;
        $selected = null;

        try {
            $found = $steps->run(new DeploymentStepName(DeploymentProgressPhase::Rollback), $request, function () use ($instance, $releaseName, &$selected): array {
                // A failed selection reports the selection it found.
                $selected = $this->deployment->selected($instance);

                return [
                    'previous' => DeployInstanceAction::storableRelease($selected),
                    'release' => DeployInstanceAction::storableRelease($this->deployment->retained($instance, $releaseName)),
                ];
            });
            // A resumed attempt reads the releases that the step stored.
            $selected = DeployInstanceAction::storedRelease(is_array($found) ? ($found['previous'] ?? null) : null);
            $release = DeployInstanceAction::storedRelease(is_array($found) ? ($found['release'] ?? null) : null)
                ?? throw new ResourceOperationException('deployment.interrupted', 'The retained release is missing.', 500);
            $boundary = DeploymentFailureBoundary::Activation;
            $activated = $steps->run(new DeploymentStepName(DeploymentProgressPhase::Activation), $request, function () use ($instance, $release, &$selected): ?array {
                $selected = $this->deployment->activate($instance, $release);
                $instance->update(['checkout_path' => $selected->path]);

                return DeployInstanceAction::storableRelease($selected);
            });
            $selected = DeployInstanceAction::storedRelease($activated) ?? $selected;

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
                ($this->processes ?? app(RestartInstanceProcessesAction::class))->execute($instance, $request);

                return null;
            });

            return DeploymentResult::succeeded($release);
        } catch (Throwable $exception) {
            if ($boundary === DeploymentFailureBoundary::Activation) {
                $selected = $this->selectionAfterActivationFailure($instance, $selected);
            }

            return DeploymentResult::failed(
                $release,
                $selected,
                $boundary,
                DeploymentErrorCode::fromException($exception),
            );
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
}
