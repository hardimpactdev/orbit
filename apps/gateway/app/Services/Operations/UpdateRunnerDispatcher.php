<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\UpdateLease;
use Closure;
use RuntimeException;

final readonly class UpdateRunnerDispatcher
{
    public function __construct(
        private FleetUpdateLease $fleetLease,
        private UpdateRunnerLauncher $runnerLauncher,
    ) {}

    public function reserve(OperationRun $operationRun): UpdateLease
    {
        return $this->fleetLease->reserve($operationRun);
    }

    public function releaseReservation(OperationRun $operationRun, UpdateLease $reservation): void
    {
        $this->fleetLease->releaseReservation($operationRun, $reservation);
    }

    /**
     * @param  Closure(RuntimeException): void  $onLaunchFailure
     */
    public function dispatchAfterResponse(
        OperationRun $operationRun,
        UpdateLease $reservation,
        Closure $onLaunchFailure,
    ): void {
        app()->terminating(function () use ($operationRun, $reservation, $onLaunchFailure): void {
            try {
                $this->runnerLauncher->launch($operationRun, $reservation);
            } catch (RuntimeException $exception) {
                try {
                    $this->fleetLease->releaseReservation($operationRun, $reservation);
                } finally {
                    $onLaunchFailure($exception);
                }
            }
        });
    }
}
