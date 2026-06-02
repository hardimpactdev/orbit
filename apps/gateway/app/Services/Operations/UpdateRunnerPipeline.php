<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;

class UpdateRunnerPipeline
{
    public function __construct(
        private readonly GatewayServiceUpdater $gatewayService,
        private readonly WorkloadNodeUpdater $workloadNodes,
        private readonly FleetUpdateVerifier $verifier,
    ) {}

    public function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->gatewayService->update($operationRun, $plan);
    }

    public function updateWorkloads(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->workloadNodes->update($operationRun, $plan);
    }

    public function verifyFleet(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->verifier->verify($operationRun, $plan);
    }
}
