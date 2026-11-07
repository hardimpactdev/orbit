<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

/**
 * Whether the image of a task VM host holds a usable nested fleet. The Gateway keeps no report of the image and asks no host: it
 * infers the fact from its own records. The image holds the fleet when the latest refresh run of the host promoted it. A proof admits
 * only hosts whose image includes every change of what the image carries up to its merge base ({@see TaskVmSeedFloor}).
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
        $commit = data_get($this->latestRefresh($hostNodeId)?->input, 'commit');

        return is_string($commit) && preg_match('/\A[0-9a-f]{40}\z/D', $commit) === 1 ? $commit : null;
    }

    /** The latest refresh of the host, when it promoted its image, or null. */
    private function latestRefresh(int $hostNodeId): ?Operation
    {
        $refresh = Operation::query()->where('kind', TaskVmImageRefreshIntent::Kind)->where('status', OperationStatus::Succeeded)
            ->where('target_type', 'task_vm_host')->where('target_id', $hostNodeId)->latest('id')->first();

        return $refresh instanceof Operation && data_get($refresh->result, 'refreshed') === true ? $refresh : null;
    }
}
