<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\FleetVersionReport;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final readonly class UpdateRunner
{
    private const string GatewayResourceKey = 'orbit-gateway';

    private const string SchedulerResourceKey = 'orbit-scheduler';

    public function __construct(
        private OperationRunRecorder $operationRuns,
        private OperationUpdatePlanStore $updatePlans,
        private UpdatePlanBuilder $updatePlanBuilder,
        private UpdateLeaseManager $leases,
        private FleetUpdateLease $fleetLease,
        private UpdateLeaseHeartbeatProcess $leaseHeartbeat,
        private UpdateRunnerPipeline $pipeline,
        private FleetVersionProbe $fleetVersions,
        private ActivityLogger $activityLogger,
        private GatewayCliArtifactRelay $cliArtifacts,
    ) {}

    public function start(string $operationRunId): OperationUpdatePlan
    {
        [$operationRun, $plan] = $this->loadRunnableContext($operationRunId);

        if (! $plan instanceof OperationUpdatePlan) {
            throw new RuntimeException("Operation update plan for run [{$operationRunId}] was not found.");
        }

        $this->markStarted($operationRun, $plan);

        return $plan;
    }

    public function run(string $operationRunId, ?int $reservedFleetLeaseId = null): OperationUpdatePlan
    {
        [$operationRun, $plan] = $this->loadRunnableContext($operationRunId);
        $fleetLease = $this->claimFleetLease($operationRun, $reservedFleetLeaseId);

        $stagedArtifacts = false;
        $execute = function () use ($operationRun, $plan, &$stagedArtifacts): OperationUpdatePlan {
            return $this->execute($operationRun, $plan, $stagedArtifacts);
        };

        try {
            return $this->leaseHeartbeat->whileRunning(
                operationRun: $operationRun,
                fleetLease: $fleetLease,
                ttlSeconds: $this->fleetLease->runtimeTtlSeconds(),
                callback: $execute,
            );
        } catch (Throwable $exception) {
            $this->markFailed($operationRun, $exception);

            throw $exception;
        } finally {
            $this->cleanupStagedArtifacts($operationRun, $stagedArtifacts);
            $this->fleetLease->releaseRunner($fleetLease);
        }
    }

    private function claimFleetLease(OperationRun $operationRun, ?int $reservedFleetLeaseId): UpdateLease
    {
        try {
            return $reservedFleetLeaseId === null
                ? $this->fleetLease->acquireForRunner($operationRun)
                : $this->fleetLease->claim($operationRun, $reservedFleetLeaseId);
        } catch (Throwable $exception) {
            $this->markFailed($operationRun, $exception);

            throw $exception;
        }
    }

    private function execute(
        OperationRun $operationRun,
        ?OperationUpdatePlan $plan,
        bool &$stagedArtifacts,
    ): OperationUpdatePlan {
        if ($plan instanceof OperationUpdatePlan) {
            $this->markStarted($operationRun, $plan);
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            'lease.fleet',
            'done',
            'Fleet update lease acquired',
        );

        [$plan, $report] = $this->runCheckSteps($operationRun, $plan);
        $allCurrent = $report->outdatedCount === 0;

        if (! $allCurrent) {
            $this->runUpdatePhases($operationRun, $plan, $stagedArtifacts);
        }

        $this->markSucceeded($operationRun, $plan, $allCurrent);

        return $plan;
    }

    private function runUpdatePhases(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        bool &$stagedArtifacts,
    ): void {
        $this->runPhase(
            $operationRun,
            'update-artifacts',
            'Staging update artifacts on gateway',
            'Update artifacts staged on gateway',
            function () use ($operationRun, $plan, &$stagedArtifacts): null {
                $stagedArtifacts = true;
                $this->cliArtifacts->stage($operationRun, $plan);

                return null;
            },
        );
        $this->runPhase(
            $operationRun,
            'gateway',
            'Updating gateway services',
            'Gateway services updated',
            function () use ($operationRun, $plan): null {
                $this->updateGateway($operationRun, $plan);

                return null;
            },
        );
        $this->runPhase(
            $operationRun,
            'workload-nodes',
            'Updating workload nodes',
            'Workload nodes updated',
            function () use ($operationRun, $plan): null {
                $this->pipeline->updateWorkloads($operationRun, $plan);

                return null;
            },
        );
        $this->runPhase(
            $operationRun,
            'verification',
            'Verifying fleet update',
            'Fleet update verified',
            function () use ($operationRun, $plan): null {
                $this->pipeline->verifyFleet($operationRun, $plan);

                return null;
            },
        );
    }

    /**
     * Emit the two contract check steps before any side effect: a version check
     * that resolves the target release, and a fleet version probe that counts
     * how many installations are behind it. The probe is read-only; it never
     * mutates fleet state.
     *
     * Returns the fleet version report so the caller can short-circuit when
     * nothing is outdated.
     */
    /**
     * @return array{0: OperationUpdatePlan, 1: FleetVersionReport}
     */
    private function runCheckSteps(OperationRun $operationRun, ?OperationUpdatePlan $plan): array
    {
        $this->operationRuns->appendStep($operationRun->id, 'check-updates', 'running', 'Checking');

        if (! $plan instanceof OperationUpdatePlan) {
            $snapshot = $this->updatePlanBuilder->fromStoredStartRequest($operationRun);
            $this->runDeferredPlanMigrations($operationRun);

            $plan = $this->updatePlans->create(
                $operationRun,
                $snapshot,
            );
            $this->markStarted($operationRun, $plan);
        }

        $this->operationRuns->appendSteps($operationRun->id, [
            [
                'key' => 'check-updates',
                'status' => 'done',
                'message' => "Done: latest version is {$plan->target_version}",
            ],
            [
                'key' => 'check-fleet-versions',
                'status' => 'running',
                'message' => 'Checking',
            ],
        ]);

        $report = $this->fleetVersions->probe($operationRun, $plan);

        $payloadExtras = $this->shouldRunUpdatePhases($plan, $report)
            ? ['update_targets' => $report->progressUpdateTargets()]
            : [];

        $this->operationRuns->appendStep(
            $operationRun->id,
            'check-fleet-versions',
            'done',
            $this->fleetVersionsMessage($report, $plan),
            [],
            $payloadExtras,
        );

        return [$plan, $report];
    }

    private function shouldRunUpdatePhases(OperationUpdatePlan $plan, FleetVersionReport $report): bool
    {
        return $report->outdatedCount > 0;
    }

    private function fleetVersionsMessage(FleetVersionReport $report, OperationUpdatePlan $plan): string
    {
        if ($report->outdatedCount === 0) {
            return "Done: all nodes running on {$plan->target_version}";
        }

        $noun = $report->outdatedCount === 1 ? 'node' : 'nodes';

        return "Done: {$report->outdatedCount} outdated {$noun} found";
    }

    private function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->leases->withLease(
            resourceType: 'gateway',
            resourceKey: self::GatewayResourceKey,
            operationRun: $operationRun,
            ownerToken: $this->ownerToken($operationRun, 'gateway', self::GatewayResourceKey),
            ttlSeconds: $this->leaseTtlSeconds(),
            callback: function () use ($operationRun, $plan): void {
                $this->leases->withLease(
                    resourceType: 'scheduler',
                    resourceKey: self::SchedulerResourceKey,
                    operationRun: $operationRun,
                    ownerToken: $this->ownerToken($operationRun, 'scheduler', self::SchedulerResourceKey),
                    ttlSeconds: $this->leaseTtlSeconds(),
                    callback: function () use ($operationRun, $plan): null {
                        $this->operationRuns->appendStep(
                            $operationRun->id,
                            'lease.gateway',
                            'done',
                            'Gateway and scheduler update leases acquired',
                        );

                        return $this->updateGatewayWithSchedulerLease($operationRun, $plan);
                    },
                );
            },
        );
    }

    private function updateGatewayWithSchedulerLease(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        $this->pipeline->updateGateway($operationRun, $plan);

        return null;
    }

    private function runPhase(
        OperationRun $operationRun,
        string $key,
        string $runningMessage,
        string $doneMessage,
        callable $callback,
    ): null {
        $this->operationRuns->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (Throwable $exception) {
            $this->operationRuns->appendStep($operationRun->id, $key, 'fail', $this->phaseFailureMessage($exception));

            throw $exception;
        }

        $this->operationRuns->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }

    private function markStarted(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->operationRuns->running($operationRun->id);
        $this->operationRuns->appendStep($operationRun->id, 'runner', 'running', 'Update runner started', [
            'target_version' => $plan->target_version,
            'gateway_image' => $plan->gateway_image,
            'manifest_source' => $plan->manifest_source,
            'manifest_version' => $plan->manifest_version,
        ]);
    }

    private function markSucceeded(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        bool $allCurrent = false,
    ): void {
        $result = [
            'status' => $allCurrent ? 'skipped' : 'succeeded',
            'target_version' => $plan->target_version,
            'manifest_source' => $plan->manifest_source,
            'manifest_version' => $plan->manifest_version,
            ...($plan->usesTopologyCandidateManifest() ? ['cli_artifacts' => $plan->cli_artifacts] : []),
            ...($plan->usesTopologyCandidateManifest() ? ['agent_artifacts' => $plan->agent_artifacts ?? []] : []),
            ...(($plan->desktop_artifacts ?? []) !== [] ? ['desktop_artifacts' => $plan->desktop_artifacts] : []),
            ...($allCurrent ? ['skipped' => true] : []),
            ...$this->skippedTargets($operationRun),
        ];

        $this->operationRuns->appendComplete($operationRun->id, 0, $result);
        $this->operationRuns->succeeded($operationRun->id, result: $result);

        $this->logOutcomeActivity($operationRun, $plan, 'completed');
    }

    /**
     * @return array{skipped_targets?: array<string, string>}
     */
    private function skippedTargets(OperationRun $operationRun): array
    {
        $skips = app(FleetUpdatePreMutationSkipRegistry::class)->forOperation($operationRun->id);

        return $skips === [] ? [] : ['skipped_targets' => $skips];
    }

    private function markFailed(OperationRun $operationRun, Throwable $exception): void
    {
        $failure = $this->failurePayload($exception);

        $this->operationRuns->appendError($operationRun->id, $failure['message'], 1, [
            'code' => $failure['code'],
            ...$failure['data'],
        ]);
        $this->operationRuns->failed($operationRun->id, error: [
            'code' => $failure['code'],
            'message' => $failure['message'],
            ...($failure['data'] === [] ? [] : ['data' => $failure['data']]),
        ]);

        $plan = $this->updatePlans->forOperationRun($operationRun->id);

        if ($plan instanceof OperationUpdatePlan) {
            $this->logOutcomeActivity($operationRun, $plan, 'failed', $this->failedStep($exception));
        }
    }

    private function logOutcomeActivity(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        string $status,
        ?string $failedStep = null,
    ): void {
        try {
            $this->activityLogger->log(
                new FleetUpdateOutcomeActivity($operationRun, $plan, $status, $failedStep),
                channel: 'api',
                causer: null,
            );
        } catch (Throwable) {
            // Activity logging is best-effort; failure must not change the runner result.
        }
    }

    private function cleanupStagedArtifacts(OperationRun $operationRun, bool $stagedArtifacts): void
    {
        if (! $stagedArtifacts) {
            return;
        }

        try {
            $this->cliArtifacts->cleanup($operationRun);
        } catch (Throwable) {
            // Artifact cleanup is best-effort; the TTL cleanup handles leftovers.
        }
    }

    private function failedStep(Throwable $exception): string
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return 'verification';
        }

        if ($exception instanceof WorkloadNodeUpdateFailed) {
            return 'workloads';
        }

        if ($exception instanceof UpdateLeaseConflict) {
            return match ($exception->resourceType) {
                'gateway', 'scheduler' => 'gateway',
                'node' => 'workloads',
                default => 'fleet_lease',
            };
        }

        return 'runner';
    }

    /**
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    private function failurePayload(Throwable $exception): array
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return [
                'code' => $exception->failureCode,
                'message' => $exception->publicMessage,
                'data' => [],
            ];
        }

        if ($exception instanceof WorkloadNodeUpdateFailed) {
            return [
                'code' => 'workload_update_failed',
                'message' => $exception->getMessage(),
                'data' => [
                    'failed_targets' => $exception->failedTargets,
                    'target_results' => $exception->targetResults,
                ],
            ];
        }

        if ($exception instanceof UpdateLeaseConflict) {
            return [
                'code' => $exception->resourceType === 'node' ? 'update.node_locked' : 'update_lease_conflict',
                'message' => $exception->getMessage(),
                'data' => array_filter(
                    [
                        'resource' => "{$exception->resourceType}:{$exception->resourceKey}",
                        'resource_type' => $exception->resourceType,
                        'resource_key' => $exception->resourceKey,
                        'lease_id' => $exception->leaseId,
                        'conflicting_operation_id' => $exception->operationRunId,
                        'expires_at' => $exception->expiresAt->toIso8601String(),
                        'conflicting_node' => $this->conflictingLeaseCallerNode($exception->operationRunId),
                    ],
                    fn (mixed $value): bool => $value !== null,
                ),
            ];
        }

        return [
            'code' => 'update_runner_failed',
            'message' => 'Update runner failed.',
            'data' => [],
        ];
    }

    private function phaseFailureMessage(Throwable $exception): string
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return $exception->publicMessage;
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Update phase failed.';
    }

    private function runDeferredPlanMigrations(OperationRun $operationRun): void
    {
        $this->operationRuns->appendStep(
            $operationRun->id,
            'migrations.preflight',
            'running',
            'Preparing gateway schema',
        );

        $exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        if ($exitCode !== 0) {
            $this->operationRuns->appendStep(
                $operationRun->id,
                'migrations.preflight',
                'fail',
                'Gateway schema preparation failed',
            );

            throw new RuntimeException('Gateway schema preparation failed.');
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            'migrations.preflight',
            'done',
            'Gateway schema prepared',
        );
    }

    /**
     * @return array{0: OperationRun, 1: OperationUpdatePlan|null}
     */
    private function loadRunnableContext(string $operationRunId): array
    {
        $operationRunId = trim($operationRunId);

        if ($operationRunId === '') {
            throw new RuntimeException('Update runner operation_run_id cannot be empty.');
        }

        $operationRun = OperationRun::query()->find($operationRunId);

        if (! $operationRun instanceof OperationRun) {
            throw new RuntimeException("Operation run [{$operationRunId}] was not found.");
        }

        if ($operationRun->status->isTerminal()) {
            throw new RuntimeException("Operation run [{$operationRunId}] is already terminal.");
        }

        return [$operationRun, $this->updatePlans->forOperationRun($operationRunId)];
    }

    private function ownerToken(OperationRun $operationRun, string $resourceType, string $resourceKey): string
    {
        return hash('sha256', implode(':', [
            'update-runner',
            $operationRun->id,
            $resourceType,
            $resourceKey,
        ]));
    }

    private function leaseTtlSeconds(): int
    {
        $ttlSeconds = (int) config('orbit.updates.lease_ttl_seconds', 300);

        return max(1, $ttlSeconds);
    }

    private function conflictingLeaseCallerNode(string $operationRunId): ?string
    {
        $nodeName = OperationRun::query()
            ->with('callerNode')
            ->find($operationRunId)
            ?->callerNode
            ?->name;

        if (! is_string($nodeName)) {
            return null;
        }

        $nodeName = trim($nodeName);

        return $nodeName === '' ? null : $nodeName;
    }
}
