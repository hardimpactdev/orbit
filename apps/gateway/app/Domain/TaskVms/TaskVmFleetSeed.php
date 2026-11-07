<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

/**
 * Whether the topology seed of a task VM host holds usable ready copies of the nested fleet. The Gateway keeps no report of the
 * seed and asks no host: it infers the fact from its own records. The seed follows the image a VM boots from, and it holds the
 * copies when the latest refresh run of the host promoted that image and saved copies of the current format. A run with
 * `seed=keep` saves none, so it never counts. A provision lists the copies by format alone, so their age does not matter here,
 * except for a proof, which admits only hosts whose copies include every change of what the image carries up to its merge base ({@see TaskVmSeedFloor}).
 */
final readonly class TaskVmFleetSeed
{
    /** @param  int  $buildId  the image build that placement picked for the host */
    public function usable(int $hostNodeId, int $buildId): bool
    {
        $refresh = $this->latestRefresh($hostNodeId);

        return $refresh instanceof Operation
            && data_get($refresh->result, 'build_id') === $buildId
            && app(TaskVmSeedFloor::class)->allows($hostNodeId);
    }

    /**
     * The release commit that the current ready copies of the host were built from: the commit the Gateway ran when the refresh that
     * saved them was admitted. Null when the host has no such refresh or the refresh recorded no commit.
     */
    public function commit(int $hostNodeId): ?string
    {
        $commit = data_get($this->latestRefresh($hostNodeId)?->input, 'refresh.commit');

        return is_string($commit) && preg_match('/\A[0-9a-f]{40}\z/D', $commit) === 1 ? $commit : null;
    }

    /** The latest refresh of the host that promoted its image and saved copies of the current format, or null. */
    private function latestRefresh(int $hostNodeId): ?Operation
    {
        $refresh = Operation::query()->where('kind', TaskVmImageRefreshIntent::Kind)->where('status', OperationStatus::Succeeded)
            ->where('input->refresh->host_node_id', $hostNodeId)
            ->where(fn ($query) => $query->whereNull('input->refresh->seed')->orWhere('input->refresh->seed', TaskVmImageRefreshIntent::SeedBuild))
            ->latest('id')->first();

        return $refresh instanceof Operation && data_get($refresh->result, 'refreshed') === true
            && data_get($refresh->input, 'refresh.ready_copies.fleet.format') === TaskVmReadyCopiesIntent::Format ? $refresh : null;
    }
}
