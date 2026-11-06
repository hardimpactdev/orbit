<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;

/**
 * Whether the topology seed of a task VM host holds usable ready copies of the nested fleet. The Gateway keeps no report of the
 * seed: it reads its own records. The seed follows the host's latest promoted image, and it holds the copies when the refresh run
 * that promoted that image saved them in the current format ({@see TaskVmReadyCopiesIntent::Format}). Any other image, such as one a
 * plain image build promoted, has a seed with only the Node template. The provision of a VM that finds no usable copy starts
 * no fleet (`nested_fleet: none`); placement uses this read so that a VM which needs the fleet does not land on such a host.
 */
final readonly class TaskVmFleetSeed
{
    public function usable(int $hostNodeId): bool
    {
        // The same order that placement uses to pick the image of the host.
        $build = TaskVmImageBuild::query()->where('host_node_id', $hostNodeId)->where('state', TaskVmImageBuildState::Succeeded)
            ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id')->first();
        if (! $build instanceof TaskVmImageBuild) {
            return false;
        }
        $refresh = Operation::query()->where('kind', TaskVmImageRefreshIntent::Kind)->where('status', OperationStatus::Succeeded)
            ->where('input->refresh->host_node_id', $hostNodeId)->latest('id')->first();

        return $refresh instanceof Operation
            && data_get($refresh->result, 'refreshed') === true
            && data_get($refresh->result, 'build_id') === $build->id
            && data_get($refresh->input, 'refresh.ready_copies.fleet.format') === TaskVmReadyCopiesIntent::Format;
    }
}
