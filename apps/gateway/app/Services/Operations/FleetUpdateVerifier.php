<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\ReleaseManifest;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\RemoteShell\RunsInternalCommands;

class FleetUpdateVerifier
{
    public function __construct(
        private readonly GatewaySwarmManager $swarm,
        private readonly NodeRoleAssignments $roles,
        private readonly OperationRunRecorder $operationRuns,
        private readonly FleetUpdateTargetSelector $targets,
        private readonly ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->runVerificationStep(
            $operationRun,
            'verification.gateway',
            'Verifying orbit-gateway service',
            'orbit-gateway service verified',
            fn (): null => $this->verifyGatewayService(),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.scheduler',
            'Verifying orbit-scheduler service',
            'orbit-scheduler service verified',
            fn (): null => $this->verifySchedulerService(),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.cli',
            'Verifying workload CLI artifacts',
            'Workload CLI artifacts verified',
            fn (): null => $this->verifyWorkloadCli($operationRun, $plan),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.agent',
            'Verifying Orbit Agent artifacts',
            'Orbit Agent artifacts verified',
            fn (): null => $this->verifyAgentArtifacts($operationRun, $plan),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.role-images',
            'Verifying required role images',
            'Required role images verified',
            fn (): null => $this->verifyRequiredRoleImages($operationRun, $plan),
        );
    }

    private function verifyGatewayService(): null
    {
        if ($this->swarm->serviceImage('orbit_orbit-gateway') === null) {
            throw new FleetUpdateVerificationFailed('gateway_health_failed', 'Gateway service verification failed.');
        }

        return null;
    }

    private function verifySchedulerService(): null
    {
        if ($this->swarm->serviceImage('orbit_orbit-scheduler') === null) {
            throw new FleetUpdateVerificationFailed(
                'scheduler_health_failed',
                'Scheduler service verification failed.',
            );
        }

        return null;
    }

    private function verifyWorkloadCli(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        app(FleetUpdateAgentRestartReadiness::class)->wait($plan);

        foreach ($this->targets->workloadNodes() as $node) {
            foreach (FleetUpdateNodeCliLauncher::binPathsToVerify($node) as $binPath) {
                $result = $this->localExecutor()->runInternal(
                    node: $node,
                    commandName: 'internal:fleet-update:verify',
                    arguments: ['cli'],
                    transportOptions: [
                        'cwd' => $node->orbit_path,
                        'input' => json_encode([
                            'bin_path' => $binPath,
                        ], JSON_THROW_ON_ERROR),
                        'timeout' => 30,
                        'metadata' => [
                            'ORBIT_OPERATION_ID' => $operationRun->id,
                        ],
                    ],
                );

                if (! $result->successful()) {
                    throw new FleetUpdateVerificationFailed('cli_verification_failed', 'CLI verification failed.');
                }
            }
        }

        return null;
    }

    private function verifyAgentArtifacts(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        app(FleetUpdateAgentVerifier::class)->verify($operationRun, $plan);

        return null;
    }

    private function verifyRequiredRoleImages(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        foreach ($this->targets->workloadNodes() as $node) {
            $images = $this->requiredRoleImagesToVerify($plan, $node);

            if ($images === []) {
                continue;
            }

            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:fleet-update:verify',
                arguments: ['role-images'],
                transportOptions: [
                    'cwd' => $node->orbit_path,
                    'input' => json_encode(['images' => $images], JSON_THROW_ON_ERROR),
                    'timeout' => 60,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationRun->id,
                    ],
                ],
            );

            if (! $result->successful()) {
                throw new FleetUpdateVerificationFailed(
                    'required_image_missing',
                    'Required role image verification failed.',
                );
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImagesToVerify(OperationUpdatePlan $plan, Node $node): array
    {
        $images = FleetUpdateNodeCliLauncher::requiredRoleImages($plan, $node, $this->roles);

        if ($plan->manifest_source !== ReleaseManifest::SourceTopologyCandidate) {
            return $images;
        }

        $candidateRuntime = $plan->toSnapshot()->roleImages['orbit-frankenphp'] ?? null;

        if ($candidateRuntime === null) {
            return $images;
        }

        $stableRuntime = app(PhpRuntimeCatalog::class)->imageFor(PhpRuntimeCatalog::DEFAULT);

        return array_values(array_unique(array_map(
            static fn (string $image): string => $image === $candidateRuntime ? $stableRuntime : $image,
            $images,
        )));
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function runVerificationStep(
        OperationRun $operationRun,
        string $key,
        string $runningMessage,
        string $doneMessage,
        callable $callback,
    ): null {
        $this->operationRuns->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (FleetUpdateVerificationFailed $exception) {
            $this->operationRuns->appendStep($operationRun->id, $key, 'fail', $exception->publicMessage);

            throw $exception;
        }

        $this->operationRuns->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }
}
