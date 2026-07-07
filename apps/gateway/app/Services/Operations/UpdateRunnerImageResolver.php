<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use RuntimeException;

final readonly class UpdateRunnerImageResolver
{
    public function __construct(
        private OperationUpdatePlanStore $plans,
        private GatewaySwarmManager $swarm,
        private UpdatePlanBuilder $updatePlanBuilder,
    ) {}

    public function resolve(OperationRun|string $operationRun): string
    {
        $plan = $this->plans->forOperationRun($this->operationRunId($operationRun));

        if ($plan instanceof OperationUpdatePlan) {
            return $plan->gateway_image;
        }

        $deferredRun = $this->deferredStartRun($operationRun);

        if ($deferredRun instanceof OperationRun) {
            return $this->digestPinnedImage(
                $this->updatePlanBuilder->fromStoredStartRequest($deferredRun)->gatewayImage,
                'Deferred update runner gateway image',
            );
        }

        return $this->bootstrapGatewayImage();
    }

    private function operationRunId(OperationRun|string $operationRun): string
    {
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);

        if ($operationRunId === '') {
            throw new RuntimeException('Update runner operation_run_id cannot be empty.');
        }

        return $operationRunId;
    }

    private function deferredStartRun(OperationRun|string $operationRun): ?OperationRun
    {
        $run = $operationRun instanceof OperationRun
            ? $operationRun
            : OperationRun::query()->find($this->operationRunId($operationRun));

        if (! $run instanceof OperationRun) {
            return null;
        }

        $result = $run->result;

        return is_array($result) && is_array($result['update_start_request'] ?? null)
            ? $run
            : null;
    }

    private function bootstrapGatewayImage(): string
    {
        $configured = config('orbit.updates.gateway_image');

        if (is_string($configured) && trim($configured) !== '') {
            return $this->digestPinnedImage($configured, 'Orbit gateway bootstrap image');
        }

        $running = $this->swarm->serviceImage(GatewaySwarmManager::DeployedGatewayService);

        if ($running === null) {
            throw new RuntimeException(
                'Orbit gateway bootstrap image is not configured and the running gateway service image could not be resolved.',
            );
        }

        return $this->digestPinnedImage($running, 'Running gateway service image');
    }

    private function digestPinnedImage(string $image, string $label): string
    {
        $reference = GatewayImageReference::fromString($image);

        if (! $reference->isDigestPinned()) {
            throw new RuntimeException("{$label} must be digest-pinned.");
        }

        return $reference->canonical();
    }
}
