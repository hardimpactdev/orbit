<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use Throwable;

final readonly class GatewayUpdateDaemonLifecycle
{
    private const string SCHEDULER_SERVICE = 'orbit_orbit-scheduler';

    private const string RUNTIME_HIBERNATOR_SERVICE = 'orbit_orbit-runtime-hibernator';

    public function __construct(
        private GatewaySwarmManager $swarm,
        private OperationRunRecorder $operationRuns,
    ) {}

    /**
     * @return array{scheduler: string|null, runtime_hibernator: string|null}
     */
    public function images(): array
    {
        return [
            'scheduler' => $this->swarm->serviceImage(self::SCHEDULER_SERVICE),
            'runtime_hibernator' => $this->swarm->serviceImage(self::RUNTIME_HIBERNATOR_SERVICE),
        ];
    }

    /**
     * @param array{scheduler: string|null, runtime_hibernator: string|null} $images
     */
    public function stop(array $images): void
    {
        $this->swarm->scaleService(self::SCHEDULER_SERVICE, 0);

        if ($images['runtime_hibernator'] !== null) {
            $this->swarm->scaleService(self::RUNTIME_HIBERNATOR_SERVICE, 0);
        }
    }

    /**
     * @param array{scheduler: string|null, runtime_hibernator: string|null} $previousImages
     */
    public function start(GatewayImageReference $targetImage, array $previousImages): void
    {
        $this->swarm->updateServiceImage(self::SCHEDULER_SERVICE, $targetImage, 'stop-first');
        $this->swarm->scaleService(self::SCHEDULER_SERVICE, 1);

        if ($previousImages['runtime_hibernator'] !== null) {
            $this->swarm->updateServiceImage(self::RUNTIME_HIBERNATOR_SERVICE, $targetImage, 'stop-first');
            $this->swarm->scaleService(self::RUNTIME_HIBERNATOR_SERVICE, 1);
        }
    }

    /**
     * @param array{scheduler: string|null, runtime_hibernator: string|null} $previousImages
     */
    public function recover(OperationRun $operationRun, array $previousImages, Throwable $original): void
    {
        $this->recoverDaemon(
            $operationRun,
            [
                'service' => self::SCHEDULER_SERVICE,
                'step' => 'scheduler.recovery',
                'label' => 'orbit-scheduler',
                'error_code' => 'update.scheduler_recovery_failed',
                'error_message' => 'Scheduler recovery failed.',
                'previous_image' => $previousImages['scheduler'],
            ],
            $original,
        );

        if ($previousImages['runtime_hibernator'] !== null) {
            $this->recoverDaemon(
                $operationRun,
                [
                    'service' => self::RUNTIME_HIBERNATOR_SERVICE,
                    'step' => 'runtime-hibernator.recovery',
                    'label' => 'orbit-runtime-hibernator',
                    'error_code' => 'update.runtime_hibernator_recovery_failed',
                    'error_message' => 'Runtime hibernator recovery failed.',
                    'previous_image' => $previousImages['runtime_hibernator'],
                ],
                $original,
            );
        }
    }

    /**
     * @param array{
     *     service: string,
     *     step: string,
     *     label: string,
     *     error_code: string,
     *     error_message: string,
     *     previous_image: string|null
     * } $daemon
     */
    private function recoverDaemon(OperationRun $operationRun, array $daemon, Throwable $original): void
    {
        $this->operationRuns->appendStep(
            $operationRun->id,
            $daemon['step'],
            'running',
            "Restoring {$daemon['label']} service",
        );

        try {
            $previousImage = $daemon['previous_image'];

            if ($previousImage === null) {
                throw new \RuntimeException("Previous {$daemon['label']} image could not be inspected.");
            }

            $this->swarm->updateServiceImage(
                $daemon['service'],
                GatewayImageReference::fromString($previousImage),
                'stop-first',
            );
            $this->swarm->scaleService($daemon['service'], 1);
            $this->operationRuns->appendStep(
                $operationRun->id,
                $daemon['step'],
                'done',
                "{$daemon['label']} service restored",
            );
        } catch (Throwable $recovery) {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $daemon['step'],
                'fail',
                $recovery->getMessage(),
            );
            $this->operationRuns->appendError($operationRun->id, $daemon['error_message'], 1, [
                'code' => $daemon['error_code'],
                'recovery_command' => $this->recoveryCommand($daemon['service'], $daemon['previous_image']),
                'original_failure' => $original->getMessage(),
                'recovery_failure' => $recovery->getMessage(),
            ]);
        }
    }

    private function recoveryCommand(string $service, ?string $previousImage): string
    {
        $scaleCommand = 'docker service scale --detach=true '.escapeshellarg($service.'=1');

        if ($previousImage === null) {
            return $scaleCommand;
        }

        return (
            'docker service update --detach=true --image '
            .escapeshellarg($previousImage)
            .' --update-order '
            .escapeshellarg('stop-first')
            .' --update-failure-action rollback --update-monitor 60s '
            .escapeshellarg($service)
            ." && {$scaleCommand}"
        );
    }
}
