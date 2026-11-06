<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSettings;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Operation;
use App\Models\Project;

/**
 * Whether the topology seed of a task VM host holds usable ready copies of the nested fleet. The Gateway keeps no report of the
 * seed and asks no host: it infers the fact from its own records. The seed follows the image a VM boots from, and it holds the
 * copies when the latest refresh run of the host promoted that image and saved copies of the current format. A run with
 * `seed=keep` saves none, so it never counts. A provision lists the copies by format alone, so their age does not matter here.
 */
final readonly class TaskVmFleetSeed
{
    public function __construct(private ProofSettings $proof) {}

    /** @param  int  $buildId  the image build that placement picked for the host */
    public function usable(int $hostNodeId, int $buildId): bool
    {
        $refresh = Operation::query()->where('kind', TaskVmImageRefreshIntent::Kind)->where('status', OperationStatus::Succeeded)
            ->where('input->refresh->host_node_id', $hostNodeId)
            ->where(fn ($query) => $query->whereNull('input->refresh->seed')->orWhere('input->refresh->seed', TaskVmImageRefreshIntent::SeedBuild))
            ->latest('id')->first();

        return $refresh instanceof Operation
            && data_get($refresh->result, 'refreshed') === true
            && data_get($refresh->result, 'build_id') === $buildId
            && data_get($refresh->input, 'refresh.ready_copies.fleet.format') === TaskVmReadyCopiesIntent::Format;
    }

    /**
     * Whether a task of `$project` gets the nested fleet. The fleet is an Orbit test fleet that runs Orbit from the task's checkout, so only
     * the Orbit Project gets it, known by its repository (`proof.repository`), not by its id. A VM of any other Project records `nested_fleet: none`.
     */
    public function forProject(Project $project): bool
    {
        $orbit = $this->proof->repository();

        return $project->repository_identity === GitRepositoryIdentity::derive("https://github.com/{$orbit->owner}/{$orbit->name}");
    }
}
